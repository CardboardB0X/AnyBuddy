<?php
$url = 'http://localhost/AnyBuddy/images/AnyBuddy%20LOGO.png';
$headers = get_headers($url);
print_r($headers);
