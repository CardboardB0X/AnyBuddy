<?php
$files = glob("*.html");

foreach ($files as $file) {
    $content = file_get_contents($file);
    
    // Replace href="#" with href="javascript:void(0)"
    $content = str_replace('href="#"', 'href="javascript:void(0)"', $content);
    
    // Add minlength="8" to all password inputs that don't already have it
    $content = preg_replace_callback('/(<input[^>]*type=["\']password["\'][^>]*)>/i', function($matches) {
        $tag = $matches[1];
        if (stripos($tag, 'minlength') === false) {
            return $tag . ' minlength="8">';
        }
        return $matches[0];
    }, $content);
    
    // Add required to email inputs if not present
    $content = preg_replace_callback('/(<input[^>]*type=["\']email["\'][^>]*)>/i', function($matches) {
        $tag = $matches[1];
        if (stripos($tag, 'required') === false) {
            return $tag . ' required>';
        }
        return $matches[0];
    }, $content);

    // Save changes
    file_put_contents($file, $content);
}
echo "HTML validation attributes and placeholder links updated.\n";
