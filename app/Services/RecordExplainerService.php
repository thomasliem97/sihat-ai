<?php

namespace App\Services;

use App\Enums\ExplainerMessageRole;
use App\Enums\Modality;
use App\Models\MedicalRecord;
use App\Models\RecordExplainerMessage;
use App\Models\User;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecordExplainerService
{
    /**
     * @param  array<string, mixed>|null  $selectedBox
     * @return array{user: RecordExplainerMessage, assistant: RecordExplainerMessage}
     */
    public function ask(
        MedicalRecord $record,
        User $actor,
        string $question,
        ?int $findingIndex = null,
        ?array $selectedBox = null,
    ): array {
        $userMessage = $this->storeUser($record, $actor, $question, $findingIndex, $selectedBox);
        $answer = $this->outOfFieldAnswer($record, $question, $actor->isPhysician())
            ?? $this->callModel($record, $actor, $userMessage, $findingIndex, $selectedBox);
        $assistant = $this->storeAssistant($record, $actor, $answer, $findingIndex, $selectedBox);

        return ['user' => $userMessage, 'assistant' => $assistant];
    }

    /**
     * @param  array<string, mixed>|null  $selectedBox
     */
    public function streamAsk(
        MedicalRecord $record,
        User $actor,
        string $question,
        ?int $findingIndex = null,
        ?array $selectedBox = null,
    ): StreamedResponse {
        $userMessage = $this->storeUser($record, $actor, $question, $findingIndex, $selectedBox);

        return response()->stream(function () use ($record, $actor, $userMessage, $question, $findingIndex, $selectedBox): void {
            set_time_limit(0);
            ignore_user_abort(true);
            session_write_close();

            try {
                $this->emitSse([
                    'event' => 'user',
                    'message' => $this->messagePayload($userMessage),
                ]);

                $refusal = $this->outOfFieldAnswer($record, $question, $actor->isPhysician());
                if ($refusal !== null) {
                    $this->emitSse([
                        'event' => 'hop',
                        'hop' => 'Checking the question against this study',
                        'detail' => $record->imagingModality()->label(),
                    ]);
                    $this->emitSse([
                        'event' => 'token',
                        'token' => $refusal,
                    ]);
                    $answer = $refusal;
                } else {
                    $answer = $this->streamModel(
                        $record,
                        $actor,
                        $userMessage,
                        $findingIndex,
                        $selectedBox,
                        function (string $token): void {
                            $this->emitSse([
                                'event' => 'token',
                                'token' => $token,
                            ]);
                        },
                        function (string $hop, ?string $detail = null): void {
                            $payload = [
                                'event' => 'hop',
                                'hop' => $hop,
                            ];
                            if (is_string($detail) && $detail !== '') {
                                $payload['detail'] = $detail;
                            }
                            $this->emitSse($payload);
                        },
                    );
                }

                if ($answer === '') {
                    $answer = $this->fallbackAnswer($actor->isPhysician());
                    $this->emitSse([
                        'event' => 'token',
                        'token' => $answer,
                    ]);
                }

                $suggestions = $this->nextSuggestions(
                    $actor->isPhysician(),
                    $userMessage->content,
                    $answer,
                    $record->findings ?? [],
                    $record,
                );

                $assistant = $this->storeAssistant($record, $actor, $answer, $findingIndex, $selectedBox);
                $this->emitSse([
                    'event' => 'suggestions',
                    'suggestions' => $suggestions,
                ]);
                $this->emitSse([
                    'event' => 'assistant',
                    'message' => $this->messagePayload($assistant),
                    'suggestions' => $suggestions,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Scan explainer stream failed', ['error' => $e->getMessage()]);
                $answer = $this->fallbackAnswer($actor->isPhysician());
                $this->emitSse([
                    'event' => 'token',
                    'token' => $answer,
                ]);
                $assistant = $this->storeAssistant($record, $actor, $answer, $findingIndex, $selectedBox);
                $this->emitSse([
                    'event' => 'assistant',
                    'message' => $this->messagePayload($assistant),
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return list<string>
     */
    public function suggestions(bool $physician, ?MedicalRecord $record = null): array
    {
        $findings = $record === null ? [] : ($record->findings ?? []);

        return $this->chooseChips($physician, $record, $findings, '', '');
    }

    /**
     * Fresh follow-up chips for the next turn, rotated from the latest Q&A.
     *
     * @param  list<array<string, mixed>>  $findings
     * @return list<string>
     */
    public function nextSuggestions(bool $physician, string $question, string $answer, array $findings = [], ?MedicalRecord $record = null): array
    {
        if ($findings === [] && $record?->findings) {
            $findings = $record->findings;
        }

        return $this->chooseChips($physician, $record, $findings, $question, $answer);
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return list<string>
     */
    private function chooseChips(
        bool $physician,
        ?MedicalRecord $record,
        array $findings,
        string $question,
        string $answer,
    ): array {
        $pool = $this->contextualSuggestionPool($physician, $record, $findings);
        $usable = array_values(array_filter(
            $pool,
            fn (string $chip): bool => ! $this->suggestionMatchesQuestion($chip, $question)
                && $this->chipFitsStudy($chip, $record),
        ));
        if (count($usable) < 4) {
            $usable = array_values(array_filter(
                $this->scopedSuggestionPool($pool, $record),
                fn (string $chip): bool => ! $this->suggestionMatchesQuestion($chip, $question),
            ));
        }

        $count = count($usable);
        if ($count === 0) {
            return array_slice($this->baseSuggestions($physician), 0, 4);
        }

        if ($question === '' && $answer === '') {
            return array_slice($usable, 0, 4);
        }

        $start = abs(crc32($question."\0".$answer)) % $count;
        $picked = [];
        for ($i = 0; $i < $count && count($picked) < 4; $i++) {
            $chip = $usable[($start + $i) % $count];
            if (! in_array($chip, $picked, true)) {
                $picked[] = $chip;
            }
        }

        return $picked;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return list<string>
     */
    private function contextualSuggestionPool(bool $physician, ?MedicalRecord $record, array $findings): array
    {
        $pool = $this->findingChips($physician, $findings);
        $pool = [...$pool, ...$this->baseSuggestions($physician)];
        $visible = $this->regionsForSuggestions($record, $findings);

        if ($physician && in_array('chest', $visible, true)) {
            $pool[] = 'Is the heart size within normal limits here?';
            $pool[] = 'Are there signs I might be missing at the apices?';
        }

        if ($physician) {
            $pool[] = 'How would you describe the laterality of this finding?';
            $pool[] = 'What would make you recommend a follow-up study?';
        } else {
            $pool[] = 'Does this explain the symptoms I came in with?';
            $pool[] = 'Is anything on this scan looking normal?';
            $pool[] = 'What usually happens after a scan like this?';
            $pool[] = 'What should I watch for at home?';
        }

        return array_values(array_unique($pool));
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return list<string>
     */
    private function findingChips(bool $physician, array $findings): array
    {
        $chips = [];
        foreach (array_slice($findings, 0, 4) as $finding) {
            $label = trim((string) ($finding['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $chips[] = $physician
                ? "What should I make of {$label} on this study?"
                : "What does {$label} mean for me?";
        }

        return $chips;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return list<string>
     */
    private function regionsForSuggestions(?MedicalRecord $record, array $findings): array
    {
        if ($record !== null) {
            return $this->visibleAnatomyRegions($record);
        }

        $text = '';
        foreach ($findings as $finding) {
            $text .= ' '.(string) ($finding['label'] ?? '');
            $text .= ' '.(string) ($finding['description'] ?? '');
        }

        return $this->anatomyRegions($text);
    }

    private function suggestionMatchesQuestion(string $chip, string $question): bool
    {
        $chipFold = mb_strtolower(trim($chip));
        $questionFold = mb_strtolower(trim($question));
        if ($chipFold === '' || $questionFold === '') {
            return false;
        }
        if ($chipFold === $questionFold) {
            return true;
        }
        if (str_contains($questionFold, $chipFold) || str_contains($chipFold, $questionFold)) {
            return true;
        }
        similar_text($chipFold, $questionFold, $percent);

        return $percent >= 72.0;
    }

    /**
     * @return array{id: int, role: string, content: string, finding_index: int|null, created_at: string|null}
     */
    public function messagePayload(RecordExplainerMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role->value,
            'content' => $message->content,
            'finding_index' => $message->finding_index,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $selectedBox
     */
    private function storeUser(
        MedicalRecord $record,
        User $actor,
        string $question,
        ?int $findingIndex,
        ?array $selectedBox,
    ): RecordExplainerMessage {
        return $record->explainerMessages()->create([
            'user_id' => $actor->id,
            'role' => ExplainerMessageRole::User,
            'content' => $question,
            'finding_index' => $findingIndex,
            'selected_box' => $selectedBox,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $selectedBox
     */
    private function storeAssistant(
        MedicalRecord $record,
        User $actor,
        string $answer,
        ?int $findingIndex,
        ?array $selectedBox,
    ): RecordExplainerMessage {
        return $record->explainerMessages()->create([
            'user_id' => $actor->id,
            'role' => ExplainerMessageRole::Assistant,
            'content' => $answer,
            'finding_index' => $findingIndex,
            'selected_box' => $selectedBox,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $selectedBox
     */
    private function callModel(
        MedicalRecord $record,
        User $actor,
        RecordExplainerMessage $current,
        ?int $findingIndex,
        ?array $selectedBox,
    ): string {
        $payload = $this->modelPayload($record, $actor, $current, $findingIndex, $selectedBox);
        $baseUrl = rtrim((string) config('services.modal.url'), '/');

        try {
            $response = Http::timeout(180)->post("{$baseUrl}/api/v1/explain", $payload);
            if ($response->successful()) {
                $answer = trim((string) $response->json('answer'));
                if ($answer !== '') {
                    return $answer;
                }
            }

            Log::warning('Scan explainer Modal call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Scan explainer Modal call exception', ['error' => $e->getMessage()]);
        }

        return $this->fallbackAnswer($actor->isPhysician());
    }

    /**
     * @param  array<string, mixed>|null  $selectedBox
     * @param  callable(string): void  $onToken
     * @param  callable(string, ?string): void  $onHop
     */
    private function streamModel(
        MedicalRecord $record,
        User $actor,
        RecordExplainerMessage $current,
        ?int $findingIndex,
        ?array $selectedBox,
        callable $onToken,
        callable $onHop,
    ): string {
        $payload = $this->modelPayload($record, $actor, $current, $findingIndex, $selectedBox);
        $this->emitPrepHops($record, $current->content, $findingIndex, $selectedBox, $onHop);
        $baseUrl = rtrim((string) config('services.modal.url'), '/');

        try {
            $response = Http::timeout(180)
                ->withOptions(['stream' => true])
                ->withHeaders(['Accept' => 'text/event-stream'])
                ->post("{$baseUrl}/api/v1/explain/stream", $payload);

            if ($response->successful()) {
                $answer = $this->consumeModalSse($response, $onToken, $onHop);
                if ($answer !== '') {
                    return $answer;
                }
            } else {
                Log::warning('Scan explainer Modal stream failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Scan explainer Modal stream exception', ['error' => $e->getMessage()]);
        }

        $fallback = $this->fallbackAnswer($actor->isPhysician());
        $onHop('Explainer unavailable', 'using the report on this page');
        $onToken($fallback);

        return $fallback;
    }

    /**
     * @param  callable(string): void  $onToken
     * @param  callable(string, ?string): void  $onHop
     */
    private function consumeModalSse(HttpResponse $response, callable $onToken, callable $onHop): string
    {
        $buffer = '';
        $assembled = '';
        $final = null;
        $failed = false;

        $handleBlock = function (string $block) use (&$assembled, &$final, &$failed, $onHop): void {
            foreach (preg_split("/\r\n|\n|\r/", $block) ?: [] as $line) {
                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $decoded = json_decode(substr($line, 6), true);
                if (! is_array($decoded)) {
                    continue;
                }

                if (isset($decoded['error'])) {
                    $failed = true;

                    continue;
                }

                $hop = $decoded['hop'] ?? null;
                if (is_string($hop)) {
                    $hop = trim($hop);
                    $detail = $decoded['detail'] ?? null;
                    $detail = is_string($detail) ? trim($detail) : null;
                    if ($hop !== '' && strlen($hop) <= 80) {
                        $onHop(
                            $hop,
                            ($detail !== null && $detail !== '' && strlen($detail) <= 240) ? $detail : null,
                        );
                    }

                    continue;
                }

                if (isset($decoded['token']) && is_string($decoded['token']) && $decoded['token'] !== '') {
                    $assembled .= $decoded['token'];
                }

                if (! empty($decoded['done']) && isset($decoded['answer']) && is_string($decoded['answer'])) {
                    $final = trim($decoded['answer']);
                }
            }
        };

        $body = $response->toPsrResponse()->getBody();
        while (! $body->eof()) {
            $buffer .= $body->read(512);
            $buffer = str_replace("\r\n", "\n", $buffer);
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $block = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $handleBlock($block);
            }
        }

        if ($buffer !== '') {
            $handleBlock($buffer);
        }

        if ($failed) {
            return '';
        }

        $visible = $final ?? trim($assembled);
        if ($visible !== '') {
            $onToken($visible);
        }

        return $visible;
    }

    /**
     * @param  array<string, mixed>|null  $selectedBox
     * @return array<string, mixed>
     */
    private function modelPayload(
        MedicalRecord $record,
        User $actor,
        RecordExplainerMessage $current,
        ?int $findingIndex,
        ?array $selectedBox,
    ): array {
        $path = $record->inferenceFilePath();
        $bytes = Storage::disk('local')->get($path);
        if ($bytes === null || $bytes === '') {
            throw new RuntimeException('Study file is missing for this record.');
        }

        return [
            'question' => $current->content,
            'audience' => $actor->isPhysician() ? 'physician' : 'patient',
            'language' => $record->language->value,
            'modality' => $record->imagingModality()->value,
            'study_scope' => $this->studyScopeLine($record),
            'file_b64' => base64_encode($bytes),
            'mime_type' => $record->mime_type,
            'original_filename' => $record->original_filename,
            'findings' => $record->findings ?? [],
            'selected_finding_index' => $findingIndex,
            'selected_box' => $selectedBox,
            'recent_dialog' => $this->recentDialog($record, $current),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $selectedBox
     * @param  callable(string, ?string): void  $onHop
     */
    private function emitPrepHops(
        MedicalRecord $record,
        string $question,
        ?int $findingIndex,
        ?array $selectedBox,
        callable $onHop,
    ): void {
        $onHop(
            'Reading this '.$record->imagingModality()->label(),
            $record->original_filename !== '' ? $record->original_filename : 'study file',
        );

        $boxLabel = is_array($selectedBox) ? trim((string) ($selectedBox['label'] ?? '')) : '';
        if ($boxLabel !== '') {
            $onHop('Focusing on '.$boxLabel, 'boxed region on the scan');
        } elseif ($findingIndex !== null) {
            $findings = $record->findings;
            $label = is_array($findings) ? trim((string) data_get($findings, "{$findingIndex}.label", '')) : '';
            if ($label !== '') {
                $onHop('Using finding: '.$label, 'from the report on this page');
            }
        }

        $short = trim($question);
        if (mb_strlen($short) > 120) {
            $short = mb_substr($short, 0, 117).'...';
        }
        $onHop('Asking MedGemma', $short);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitSse(array $payload): void
    {
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    private function recentDialog(MedicalRecord $record, RecordExplainerMessage $current): string
    {
        $prior = $record->explainerMessages()
            ->where('id', '<', $current->id)
            ->reorder()
            ->orderByDesc('id')
            ->limit(8)
            ->get(['role', 'content'])
            ->reverse()
            ->values();

        if ($prior->isEmpty()) {
            return '';
        }

        return $prior->map(function (RecordExplainerMessage $message): string {
            $label = $message->role === ExplainerMessageRole::Assistant ? 'Assistant' : 'User';

            return "{$label}: {$message->content}";
        })->implode("\n");
    }

    private function fallbackAnswer(bool $physician): string
    {
        if ($physician) {
            return 'The scan explainer is warming up. Ask again in a moment, or review the findings and overlay on this page.';
        }

        return 'The explainer is not available right now. Please read the signed report on this page, or try again shortly.';
    }

    /**
     * @return list<string>
     */
    private function baseSuggestions(bool $physician): array
    {
        if ($physician) {
            return [
                'Where is the main finding on this study?',
                'What else should I check besides the boxed region?',
                'How would you describe the technical quality of this view?',
                'What is the most likely next step from this image alone?',
            ];
        }

        return [
            'What does this scan show in simple words?',
            'Where should I look on the picture?',
            'Should I be worried about this today?',
            'What should I ask my doctor?',
        ];
    }

    /**
     * @param  list<string>  $pool
     * @return list<string>
     */
    private function scopedSuggestionPool(array $pool, ?MedicalRecord $record): array
    {
        return array_values(array_filter(
            $pool,
            fn (string $chip): bool => $this->chipFitsStudy($chip, $record),
        ));
    }

    private function chipFitsStudy(string $chip, ?MedicalRecord $record): bool
    {
        if ($record === null) {
            return true;
        }

        $asked = $this->anatomyRegions($chip);
        $visible = $this->visibleAnatomyRegions($record);
        if ($asked === [] || $visible === []) {
            return true;
        }

        return array_intersect($asked, $visible) !== [];
    }

    private function outOfFieldAnswer(MedicalRecord $record, string $question, bool $physician): ?string
    {
        $asked = $this->anatomyRegions($question);
        $visible = $this->visibleAnatomyRegions($record);
        if ($asked === [] || $visible === []) {
            return null;
        }
        if (array_intersect($asked, $visible) !== []) {
            return null;
        }

        $study = $record->imagingModality()->label();
        $askedPhrase = $this->regionPhrase($asked);
        $visiblePhrase = $this->regionPhrase($visible);

        if ($physician) {
            return "This {$study} does not include {$askedPhrase}. That anatomy is not in the field of view, so it cannot be assessed here. Stay with what this study actually shows: {$visiblePhrase}.";
        }

        return "This scan does not show {$askedPhrase}. I can only talk about what is on this image: {$visiblePhrase}.";
    }

    /**
     * @return list<string>
     */
    private function visibleAnatomyRegions(MedicalRecord $record): array
    {
        foreach ([
            $this->findingAnatomyText($record),
            (string) $record->title,
            (string) $record->original_filename,
        ] as $source) {
            $regions = $this->anatomyRegions($source);
            if ($regions !== []) {
                return $regions;
            }
        }

        return match ($record->imagingModality()) {
            Modality::Xray => ['chest'],
            Modality::Dermatology => ['skin'],
            Modality::Ophthalmology => ['eye'],
            default => [],
        };
    }

    private function findingAnatomyText(MedicalRecord $record): string
    {
        $parts = [];

        foreach ($record->findings ?? [] as $finding) {
            $parts[] = (string) ($finding['label'] ?? '');
            $parts[] = (string) ($finding['description'] ?? '');
        }

        foreach (is_array($record->bounding_boxes) ? $record->bounding_boxes : [] as $box) {
            if (is_array($box)) {
                $parts[] = (string) ($box['label'] ?? '');
            }
        }

        return implode(' ', array_filter($parts, fn (string $part): bool => trim($part) !== ''));
    }

    private function studyScopeLine(MedicalRecord $record): string
    {
        $visible = $this->visibleAnatomyRegions($record);
        $study = $record->imagingModality()->label();
        if ($visible === []) {
            return "This is a {$study}. Answer only anatomy that is actually in the attached images.";
        }

        return "This is a {$study} covering {$this->regionPhrase($visible)}. Do not describe organs outside this field of view.";
    }

    /**
     * @return list<string>
     */
    private function anatomyRegions(string $text): array
    {
        $hay = mb_strtolower($text);
        if ($hay === '') {
            return [];
        }

        $found = [];
        foreach ([
            'head' => ['head', 'brain', 'intracranial', 'calvarium', 'skull', 'cerebral', 'cerebell', 'mastoid', 'paranasal', 'ventricle', 'sulci', 'crani', 'scalp', 'orbit'],
            'chest' => ['heart', 'cardiac', 'cardiomeg', 'lung', 'pulmonary', 'thorax', 'chest', 'cxr', 'pleura', 'mediastin', 'apices', 'apex', 'cardiothoracic', 'hilar', 'hilum'],
            'abdomen' => ['liver', 'hepatic', 'spleen', 'kidney', 'renal', 'bowel', 'abdomen', 'pancrea', 'gallbladder'],
            'pelvis' => ['pelvis', 'uterus', 'ovary', 'prostate', 'bladder'],
            'spine' => ['spine', 'vertebral', 'vertebra', 'spinal'],
        ] as $region => $terms) {
            foreach ($terms as $term) {
                $pattern = in_array($term, ['cardiomeg', 'mediastin', 'cerebell', 'crani', 'pancrea', 'paranasal'], true)
                    ? '/\b'.preg_quote($term, '/').'/u'
                    : '/\b'.preg_quote($term, '/').'\b/u';
                if (preg_match($pattern, $hay) === 1) {
                    $found[] = $region;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  list<string>  $regions
     */
    private function regionPhrase(array $regions): string
    {
        $labels = array_map(fn (string $region): string => match ($region) {
            'head' => 'the head',
            'chest' => 'the chest',
            'abdomen' => 'the abdomen',
            'pelvis' => 'the pelvis',
            'spine' => 'the spine',
            'skin' => 'the skin',
            'eye' => 'the eyes',
            default => $region,
        }, $regions);

        $count = count($labels);
        if ($count === 0) {
            return 'the imaged region';
        }
        if ($count === 1) {
            return $labels[0];
        }
        if ($count === 2) {
            return $labels[0].' and '.$labels[1];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).', and '.$last;
    }
}
