<?php
require_once 'config/config.php';
require_once 'src/communication.php';

// Run the migration
migrateSafetyTalksToStatusColumn($conn);
echo "Migration completed successfully!";
?>