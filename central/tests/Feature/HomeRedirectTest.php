<?php

it('redirects the home page to the admin panel', function () {
    $this->get('/')->assertRedirect('/admin');
});
