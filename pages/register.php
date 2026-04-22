<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Redirect if already logged in
if (isParent()) {
    header('Location: ' . BASE_URL . 'pages/parent_dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($email) || empty($password) || empty($confirm)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM parents WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO parents (name, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $hashed]);
            $success = 'Account created! You can now sign in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — ChoreQuest</title>
    <meta name="description" content="Create a ChoreQuest parent account to start managing your family's chores.">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/shared.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: {
                            50: '#f0f4ff', 100: '#dbe4ff', 200: '#bac8ff',
                            300: '#91a7ff', 400: '#748ffc', 500: '#5c7cfa',
                            600: '#4c6ef5', 700: '#4263eb', 800: '#3b5bdb', 900: '#364fc7',
                        },
                        mint: {
                            50: '#e6fcf5', 100: '#c3fae8', 200: '#96f2d7',
                            300: '#63e6be', 400: '#38d9a9', 500: '#20c997',
                        },
                        coral: { 400: '#ff8787', 500: '#ff6b6b', 600: '#fa5252' },
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-gray-950 text-white overflow-hidden relative">

    <!-- Background Orbs -->
    <div class="orb w-96 h-96 bg-purple-600 -top-20 -right-20" style="animation-delay: 0s;"></div>
    <div class="orb w-80 h-80 bg-brand-600 bottom-0 -left-20" style="animation-delay: 3s;"></div>

    <div class="relative z-10 min-h-screen flex items-center justify-center px-4 py-8">
        <div class="w-full max-w-md">

            <div class="text-center mb-8">
                <div class="float-animation inline-block text-5xl mb-3">👨‍👩‍👧‍👦</div>
                <h1 class="text-3xl font-black tracking-tight">Create Parent Account</h1>
                <p class="text-gray-400 mt-2 text-sm">Set up your family's ChoreQuest HQ.</p>
            </div>

            <div class="glass-strong rounded-2xl p-8 shadow-2xl">

                <?php if ($error): ?>
                    <div class="mb-6 p-3 rounded-xl bg-coral-600/20 border border-coral-600/30 text-coral-400 text-sm text-center">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="mb-6 p-3 rounded-xl bg-emerald-600/20 border border-emerald-600/30 text-emerald-400 text-sm text-center">
                        <?= htmlspecialchars($success) ?>
                        <a href="<?= BASE_URL ?>" class="underline font-semibold ml-1">Sign in now →</a>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-5">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Your Name</label>
                        <input type="text" name="name" placeholder="Jane Doe" required
                            value="<?= htmlspecialchars($name ?? '') ?>"
                            class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Email Address</label>
                        <input type="email" name="email" placeholder="parent@email.com" required
                            value="<?= htmlspecialchars($email ?? '') ?>"
                            class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Password</label>
                        <input type="password" name="password" placeholder="Min. 6 characters" required
                            class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Confirm Password</label>
                        <input type="password" name="confirm_password" placeholder="Re-enter password" required
                            class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-gray-500 outline-none">
                    </div>
                    <button type="submit" class="btn-primary w-full py-3.5 rounded-xl text-white font-bold text-sm uppercase tracking-wider">
                        Create Account
                    </button>
                </form>

                <div class="mt-6 text-center">
                    <p class="text-gray-500 text-sm">
                        Already have an account?
                        <a href="<?= BASE_URL ?>" class="text-brand-400 hover:text-brand-300 font-semibold transition-colors">Sign In →</a>
                    </p>
                </div>
            </div>

            <p class="text-center text-gray-600 text-xs mt-6">
                ChoreQuest &copy; <?= date('Y') ?>
            </p>
        </div>
    </div>
</body>
</html>
