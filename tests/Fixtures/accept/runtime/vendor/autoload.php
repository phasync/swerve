<?php
// Composer's autoload.php, as a project with symfony/runtime has it; swerve's worker includes it before a request
require_once __DIR__ . '/RuntimeFixture.php';

return 'the class loader';
