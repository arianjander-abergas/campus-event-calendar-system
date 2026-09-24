<?php
<<<<<<< Updated upstream
require_once __DIR__ . '/config/supabase.php';
supabase_auth_signout();
header('Location: index.php');
=======
require_once __DIR__ . '/../../config/supabase.php';
supabase_auth_signout();
header('Location: ../index.php');
>>>>>>> Stashed changes
exit;
