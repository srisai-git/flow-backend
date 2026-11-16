<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/log_distraction.php';
} else {
    require __DIR__ . '/get_distractions.php';
}
