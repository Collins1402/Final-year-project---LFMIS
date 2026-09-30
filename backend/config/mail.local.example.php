<?php
// Copy to mail.local.php and fill in. For Gmail, use an App Password: https://myaccount.google.com/apppasswords
return [
    'host' => 'smtp.gmail.com',
    'port' => 465,
    'username' => 'your-address@gmail.com',
    'password' => 'your-16-character-app-password',
    'from' => 'your-address@gmail.com',
    'fromName' => 'Flouri Farm LFMIS',
    // Set if PHP cannot verify the SMTP certificate, e.g. 'C:/xampp/apache/bin/curl-ca-bundle.crt'
    'cafile' => '',
];
