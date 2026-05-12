<?php
$title = $title ?? 'SEJAHUB';
?>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($title) ?></title>

    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="assets/sejahub_icon.png">

    <!-- TAILWIND -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- GOOGLE FONT -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- LUCIDE ICON -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #ffffff;
            color: #111827;
        }

        .border-subtle {
            border-color: #f0f0f0;
        }

        .input {
            background: #f9f9f9;
            border: 1px solid #f0f0f0;
            transition: all 0.2s ease;
        }

        .input:focus {
            outline: none;
            border-color: #000;
            background: #fff;
        }

        .btn {
            border-radius: 4px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            transition: all 0.15s ease;
        }

        .btn-primary {
            background: #000;
            color: #fff;
        }

        .btn-primary:hover {
            background: #1f1f1f;
        }

        .card {
            background: #fff;
            border: 1px solid #f0f0f0;
        }
    </style>
</head>