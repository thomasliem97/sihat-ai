<?php

test('registration routes are disabled', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});
