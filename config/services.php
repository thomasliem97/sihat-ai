<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'modal' => [
        'url' => env('SIHAT_AI_URL', 'https://thomasliem--sihat-medgemma-web.modal.run'),
        'webhook_secret' => env('SIHAT_AI_WEBHOOK_SECRET'),
        'lora_path' => env('SIHAT_AI_LORA_PATH'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'structure_model' => env('OPENAI_STRUCTURE_MODEL', 'gpt-5.6-terra'),
        'structure_effort' => env('OPENAI_STRUCTURE_EFFORT', 'high'),
    ],

    'triage' => [
        'stt_en_engine' => env('TRIAGE_STT_EN', 'medasr'),
        'stt_other_engine' => env('TRIAGE_STT_OTHER', 'whisper'),
        'tts_model' => env('TRIAGE_TTS_MODEL', 'gpt-4o-mini-tts'),
        'tts_voice' => env('TRIAGE_TTS_VOICE', 'marin'),
        'tts_speed' => (float) env('TRIAGE_TTS_SPEED', 1.5),
        'tts_instructions' => env(
            'TRIAGE_TTS_INSTRUCTIONS',
            'Voice identity: A warm, polished female clinical professional speaking face to face with a patient. Affect: Calm, composed, reassuring, and gently upbeat, with quiet confidence. Tone: Human, sincere, empathetic, and conversational; sound attentive rather than scripted. Pacing: Natural and moderate, never rushed. Use subtle changes in rhythm and intonation, with brief pauses after questions, important guidance, and reassuring statements. Emotion: Let a pleasant warmth show in greetings and routine guidance, but become appropriately serious and grounded for distressing symptoms, urgent advice, or safety warnings. Pronunciation: Clear and precise without over-enunciating; emphasize medication names, doses, timeframes, warning signs, and next steps when present. Delivery: Speak fluidly as one clinician to one patient. Avoid a flat monotone, sing-song cadence, exaggerated cheerfulness, announcer voice, phone-menu rhythm, or chatbot-like delivery.',
        ),
        'structure_model' => env('TRIAGE_STRUCTURE_MODEL'),
        'structure_effort' => env('TRIAGE_STRUCTURE_EFFORT'),
        'detect_model' => env('TRIAGE_DETECT_MODEL', 'gpt-4o-mini'),
        'intent_model' => env('TRIAGE_INTENT_MODEL', env('TRIAGE_DETECT_MODEL', 'gpt-4o-mini')),
        'intent_confidence_min' => (float) env('TRIAGE_INTENT_CONFIDENCE_MIN', 0.85),
    ],

];
