<?php
require_once __DIR__ . '/config/supabase.php';
supabase_auth_signout();
header('Location: index.php');
exit;
