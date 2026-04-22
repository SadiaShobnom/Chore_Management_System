<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Redirect if already logged in
if (isParent()) {
    header('Location: ' . BASE_URL . 'pages/parent_dashboard.php');
    exit;
}
if (isKid()) {
    header('Location: ' . BASE_URL . 'pages/kid_dashboard.php');
    exit;
}

$error = '';
$login_type = isset($_GET['type']) ? $_GET['type'] : 'parent';

// Fetch all kids grouped by parent for avatar selection
$all_kids = [];
$stmt = $pdo->query("SELECT k.id, k.name, k.avatar, k.username, k.pin, p.name as parent_name FROM kids k JOIN parents p ON k.parent_id = p.id ORDER BY p.name, k.name");
$all_kids = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_type = $_POST['login_type'] ?? 'parent';

    if ($login_type === 'parent') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please fill in all fields.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM parents WHERE email = ?");
            $stmt->execute([$email]);
            $parent = $stmt->fetch();

            if ($parent && password_verify($password, $parent['password'])) {
                $_SESSION['parent_id'] = $parent['id'];
                $_SESSION['parent_name'] = $parent['name'];
                header('Location: ' . BASE_URL . 'pages/parent_dashboard.php');
                exit;
            } else {
                $error = 'Invalid email or password.';
            }
        }
    } elseif ($login_type === 'kid_pin') {
        // Avatar + PIN login
        $kid_id = intval($_POST['kid_id'] ?? 0);
        $pin = trim($_POST['pin'] ?? '');

        if ($kid_id === 0 || empty($pin)) {
            $error = 'Please select your avatar and enter your PIN.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM kids WHERE id = ? AND pin = ?");
            $stmt->execute([$kid_id, $pin]);
            $kid = $stmt->fetch();

            if ($kid) {
                $_SESSION['kid_id'] = $kid['id'];
                $_SESSION['kid_name'] = $kid['name'];
                $_SESSION['kid_avatar'] = $kid['avatar'];
                header('Location: ' . BASE_URL . 'pages/kid_dashboard.php');
                exit;
            } else {
                $error = 'Wrong PIN! Try again.';
            }
        }
    } else {
        // Classic username/password kid login
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please fill in all fields.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM kids WHERE username = ?");
            $stmt->execute([$username]);
            $kid = $stmt->fetch();

            if ($kid && password_verify($password, $kid['password'])) {
                $_SESSION['kid_id'] = $kid['id'];
                $_SESSION['kid_name'] = $kid['name'];
                $_SESSION['kid_avatar'] = $kid['avatar'];
                header('Location: ' . BASE_URL . 'pages/kid_dashboard.php');
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ChoreQuest — Home Chore Management</title>
    <meta name="description" content="ChoreQuest: A fun and engaging home chore management system for families. Parents assign, kids conquer!">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
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
                        amber: { 400: '#ffd43b', 500: '#fcc419', 600: '#fab005' },
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-gray-950 text-white overflow-hidden relative">

    <!-- Background Orbs -->
    <div class="orb w-96 h-96 bg-brand-600 -top-20 -left-20" style="animation-delay: 0s;"></div>
    <div class="orb w-80 h-80 bg-purple-600 top-1/2 -right-20" style="animation-delay: 2s;"></div>
    <div class="orb w-72 h-72 bg-mint-500 -bottom-20 left-1/3" style="animation-delay: 4s;"></div>

    <div class="relative z-10 min-h-screen flex items-center justify-center px-4 py-8">
        <div class="w-full max-w-md">

            <!-- Logo / Header -->
            <div class="text-center mb-8">
                <div class="float-animation inline-block text-6xl mb-4">🏠</div>
                <h1 class="text-4xl font-black tracking-tight">
                    Chore<span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-400 to-purple-400">Quest</span>
                </h1>
                <p class="text-gray-400 mt-2 text-sm">Family chore management, made fun.</p>
            </div>

            <!-- Login Card -->
            <div class="glass-strong rounded-2xl p-8 shadow-2xl">

                <!-- Tab Switcher -->
                <div class="flex gap-2 mb-8 p-1 rounded-xl bg-white/5">
                    <button onclick="switchTab('parent')" id="tab-parent"
                        class="flex-1 py-2.5 px-4 rounded-lg text-sm font-semibold transition-all duration-300 tab-active">
                        👨‍👩‍👧 Parent
                    </button>
                    <button onclick="switchTab('kid')" id="tab-kid"
                        class="flex-1 py-2.5 px-4 rounded-lg text-sm font-semibold transition-all duration-300 tab-inactive">
                        🧒 Kid
                    </button>
                </div>

                <?php if ($error): ?>
                    <div class="mb-6 p-3 rounded-xl bg-coral-600/20 border border-coral-600/30 text-coral-400 text-sm text-center bounce-in">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <!-- Parent Login Form -->
                <form id="form-parent" method="POST" class="space-y-5" style="display: <?= $login_type === 'parent' ? 'block' : 'none' ?>;">
                    <input type="hidden" name="login_type" value="parent">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Email Address</label>
                        <input type="email" name="email" placeholder="parent@email.com" required
                            class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-gray-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Password</label>
                        <input type="password" name="password" placeholder="••••••••" required
                            class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-gray-500 outline-none">
                    </div>
                    <button type="submit" class="btn-primary w-full py-3.5 rounded-xl text-white font-bold text-sm uppercase tracking-wider">
                        Sign In as Parent
                    </button>
                </form>

                <!-- Kid Login: Avatar Selection + PIN -->
                <div id="form-kid" style="display: <?= ($login_type === 'kid' || $login_type === 'kid_pin') ? 'block' : 'none' ?>;">

                    <?php if (!empty($all_kids)): ?>
                        <!-- Avatar Grid -->
                        <div id="kid-avatar-grid">
                            <p class="text-center text-gray-400 text-sm mb-4">Tap your avatar to sign in!</p>
                            <div class="grid grid-cols-3 gap-3 mb-4">
                                <?php foreach ($all_kids as $k): ?>
                                    <button type="button" onclick="selectKidAvatar(<?= $k['id'] ?>, '<?= e($k['name']) ?>', '<?= $k['avatar'] ?>', <?= $k['pin'] ? 'true' : 'false' ?>)"
                                        class="kid-avatar-btn flex flex-col items-center gap-2 p-4 rounded-2xl border border-white/10 hover:border-brand-400/40 hover:bg-white/5 transition-all group">
                                        <span class="text-4xl group-hover:scale-110 transition-transform"><?= $k['avatar'] ?></span>
                                        <span class="text-xs font-semibold text-gray-400 group-hover:text-white transition-colors"><?= e($k['name']) ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- PIN Entry (shown after avatar click) -->
                        <form id="kid-pin-form" method="POST" class="space-y-5" style="display: none;">
                            <input type="hidden" name="login_type" value="kid_pin">
                            <input type="hidden" name="kid_id" id="selected-kid-id" value="">

                            <div class="text-center mb-4">
                                <div class="text-5xl mb-2 bounce-in" id="selected-kid-avatar"></div>
                                <div class="text-lg font-bold" id="selected-kid-name"></div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2 text-center">Enter Your 4-Digit PIN</label>
                                <div class="flex justify-center gap-3" id="pin-inputs">
                                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]"
                                        class="pin-digit input-field w-14 h-14 rounded-xl text-white text-2xl text-center font-black outline-none"
                                        oninput="pinDigitInput(this, 0)">
                                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]"
                                        class="pin-digit input-field w-14 h-14 rounded-xl text-white text-2xl text-center font-black outline-none"
                                        oninput="pinDigitInput(this, 1)">
                                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]"
                                        class="pin-digit input-field w-14 h-14 rounded-xl text-white text-2xl text-center font-black outline-none"
                                        oninput="pinDigitInput(this, 2)">
                                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]"
                                        class="pin-digit input-field w-14 h-14 rounded-xl text-white text-2xl text-center font-black outline-none"
                                        oninput="pinDigitInput(this, 3)">
                                </div>
                                <input type="hidden" name="pin" id="pin-combined" value="">
                            </div>

                            <button type="submit" id="pin-submit-btn" disabled
                                class="btn-primary w-full py-3.5 rounded-xl text-white font-bold text-sm uppercase tracking-wider disabled:opacity-40 disabled:cursor-not-allowed">
                                Sign In
                            </button>

                            <button type="button" onclick="backToAvatars()"
                                class="w-full py-2 text-sm text-gray-500 hover:text-white transition-colors text-center">
                                ← Pick a different profile
                            </button>
                        </form>

                        <!-- Fallback: username/password login -->
                        <form id="kid-classic-form" method="POST" class="space-y-5" style="display: none;">
                            <input type="hidden" name="login_type" value="kid">
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Username</label>
                                <input type="text" name="username" placeholder="your_username" required
                                    class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-gray-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Password</label>
                                <input type="password" name="password" placeholder="••••••••" required
                                    class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-gray-500 outline-none">
                            </div>
                            <button type="submit" class="btn-primary w-full py-3.5 rounded-xl text-white font-bold text-sm uppercase tracking-wider">
                                Sign In as Kid
                            </button>
                            <button type="button" onclick="backToAvatars()"
                                class="w-full py-2 text-sm text-gray-500 hover:text-white transition-colors text-center">
                                ← Back to avatars
                            </button>
                        </form>

                        <!-- Toggle to classic login -->
                        <div id="kid-classic-toggle" class="mt-4 text-center">
                            <button onclick="showClassicLogin()" class="text-xs text-gray-600 hover:text-gray-400 transition-colors">
                                Use username & password instead →
                            </button>
                        </div>

                    <?php else: ?>
                        <!-- No kids registered yet — show classic form -->
                        <div class="text-center py-6">
                            <div class="text-4xl mb-3">🧒</div>
                            <p class="text-gray-400 text-sm mb-4">No kid profiles yet. Ask your parent to add you!</p>
                        </div>
                        <form method="POST" class="space-y-5">
                            <input type="hidden" name="login_type" value="kid">
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Username</label>
                                <input type="text" name="username" placeholder="your_username" required
                                    class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-gray-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Password</label>
                                <input type="password" name="password" placeholder="••••••••" required
                                    class="input-field w-full px-4 py-3 rounded-xl text-white placeholder-gray-500 outline-none">
                            </div>
                            <button type="submit" class="btn-primary w-full py-3.5 rounded-xl text-white font-bold text-sm uppercase tracking-wider">
                                Sign In as Kid
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Register Link -->
                <div class="mt-6 text-center">
                    <p class="text-gray-500 text-sm">
                        New parent?
                        <a href="<?= BASE_URL ?>pages/register.php" class="text-brand-400 hover:text-brand-300 font-semibold transition-colors">
                            Create Account →
                        </a>
                    </p>
                </div>
            </div>

            <!-- Footer -->
            <p class="text-center text-gray-600 text-xs mt-6">
                ChoreQuest &copy; <?= date('Y') ?> — Making chores an adventure!
            </p>
        </div>
    </div>

    <script>
        function switchTab(type) {
            const tabParent = document.getElementById('tab-parent');
            const tabKid = document.getElementById('tab-kid');
            const formParent = document.getElementById('form-parent');
            const formKid = document.getElementById('form-kid');

            if (type === 'parent') {
                tabParent.className = tabParent.className.replace('tab-inactive', 'tab-active');
                tabKid.className = tabKid.className.replace('tab-active', 'tab-inactive');
                formParent.style.display = 'block';
                formKid.style.display = 'none';
            } else {
                tabKid.className = tabKid.className.replace('tab-inactive', 'tab-active');
                tabParent.className = tabParent.className.replace('tab-active', 'tab-inactive');
                formKid.style.display = 'block';
                formParent.style.display = 'none';
            }
        }

        function selectKidAvatar(id, name, avatar, hasPin) {
            document.getElementById('selected-kid-id').value = id;
            document.getElementById('selected-kid-avatar').textContent = avatar;
            document.getElementById('selected-kid-name').textContent = name;

            if (hasPin) {
                // Show PIN form
                document.getElementById('kid-avatar-grid').style.display = 'none';
                document.getElementById('kid-pin-form').style.display = 'block';
                document.getElementById('kid-classic-form').style.display = 'none';
                document.getElementById('kid-classic-toggle').style.display = 'none';
                // Focus first PIN input
                setTimeout(() => document.querySelector('.pin-digit').focus(), 100);
            } else {
                // No PIN set — show classic form for this kid
                document.getElementById('kid-avatar-grid').style.display = 'none';
                document.getElementById('kid-pin-form').style.display = 'none';
                document.getElementById('kid-classic-form').style.display = 'block';
                document.getElementById('kid-classic-toggle').style.display = 'none';
            }
        }

        function backToAvatars() {
            document.getElementById('kid-avatar-grid').style.display = 'block';
            document.getElementById('kid-pin-form').style.display = 'none';
            document.getElementById('kid-classic-form').style.display = 'none';
            document.getElementById('kid-classic-toggle').style.display = 'block';
            // Clear PIN
            document.querySelectorAll('.pin-digit').forEach(d => d.value = '');
            document.getElementById('pin-combined').value = '';
        }

        function showClassicLogin() {
            document.getElementById('kid-avatar-grid').style.display = 'none';
            document.getElementById('kid-classic-form').style.display = 'block';
            document.getElementById('kid-classic-toggle').style.display = 'none';
        }

        function pinDigitInput(el, idx) {
            // Only allow digits
            el.value = el.value.replace(/[^0-9]/g, '');
            if (el.value && idx < 3) {
                el.nextElementSibling?.focus();
            }
            // Combine PIN digits
            const digits = document.querySelectorAll('.pin-digit');
            let combined = '';
            digits.forEach(d => combined += d.value);
            document.getElementById('pin-combined').value = combined;
            document.getElementById('pin-submit-btn').disabled = combined.length < 4;

            // Auto-submit when 4 digits entered
            if (combined.length === 4) {
                document.getElementById('kid-pin-form').submit();
            }
        }

        // Handle backspace on PIN digits
        document.querySelectorAll('.pin-digit').forEach((digit, idx) => {
            digit.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !digit.value && idx > 0) {
                    digit.previousElementSibling?.focus();
                }
            });
        });

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('type') === 'kid') switchTab('kid');
    </script>
</body>
</html>
