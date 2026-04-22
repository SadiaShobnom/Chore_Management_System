<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireParent();

$parent_id = getParentId();
$parent_name = $_SESSION['parent_name'];
$msg = '';
$msg_type = '';
$active_tab = $_GET['tab'] ?? 'overview';

// ══════════════════════════════════════════════════════
// Handle POST actions
// ══════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add Kid ──
    if ($action === 'add_kid') {
        $kid_name = trim($_POST['kid_name'] ?? '');
        $kid_username = trim($_POST['kid_username'] ?? '');
        $kid_password = $_POST['kid_password'] ?? '';
        $kid_avatar = $_POST['kid_avatar'] ?? '🦸';
        $kid_dob = $_POST['kid_dob'] ?? '';

        if (empty($kid_name) || empty($kid_username) || empty($kid_password) || empty($kid_dob)) {
            $msg = 'All fields are required to add a kid.';
            $msg_type = 'error';
        } elseif (strlen($kid_password) < 4) {
            $msg = 'Kid password must be at least 4 characters.';
            $msg_type = 'error';
        } else {
            $age = calcAge($kid_dob);
            if ($age < 5 || $age > 18) {
                $msg = 'Kid must be between 5 and 18 years old.';
                $msg_type = 'error';
            } else {
                $stmt = $pdo->prepare("SELECT id FROM kids WHERE username = ?");
                $stmt->execute([$kid_username]);
                if ($stmt->fetch()) {
                    $msg = 'This username is already taken. Try another.';
                    $msg_type = 'error';
                } else {
                    $hashed = password_hash($kid_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO kids (parent_id, name, username, password, date_of_birth, avatar) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$parent_id, $kid_name, $kid_username, $hashed, $kid_dob, $kid_avatar]);
                    $msg = "Kid \"$kid_name\" added! Username: <strong>$kid_username</strong> • Age group: <strong>" . (getAgeGroup($kid_dob) === 'young' ? '5-10 (Treats)' : '11-18 (Savings)') . "</strong>";
                    $msg_type = 'success';
                }
            }
        }
        $active_tab = 'overview';
    }

    // ── Assign Chore ──
    if ($action === 'assign_chore') {
        $kid_id = intval($_POST['kid_id'] ?? 0);
        $title = trim($_POST['chore_title'] ?? '');
        $description = trim($_POST['chore_description'] ?? '');
        $points = intval($_POST['chore_points'] ?? 10);
        $due_date = $_POST['due_date'] ?? null;

        if (empty($title) || $kid_id === 0) {
            $msg = 'Please select a kid and enter a chore title.';
            $msg_type = 'error';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM kids WHERE id = ? AND parent_id = ?");
            $stmt->execute([$kid_id, $parent_id]);
            if (!$stmt->fetch()) {
                $msg = 'Invalid kid selected.';
                $msg_type = 'error';
            } else {
                $stmt = $pdo->prepare("INSERT INTO chores (parent_id, kid_id, title, description, points, due_date) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$parent_id, $kid_id, $title, $description, $points, $due_date ?: null]);
                $msg = "Chore \"$title\" assigned successfully!";
                $msg_type = 'success';
            }
        }
        $active_tab = 'chores';
    }

    // ── Delete Chore ──
    if ($action === 'delete_chore') {
        $chore_id = intval($_POST['chore_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM chores WHERE id = ? AND parent_id = ?");
        $stmt->execute([$chore_id, $parent_id]);
        $msg = 'Chore deleted.';
        $msg_type = 'success';
        $active_tab = 'chores';
    }

    // ── Approve Chore ──
    if ($action === 'approve_chore') {
        $chore_id = intval($_POST['chore_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM chores WHERE id = ? AND parent_id = ? AND status = 'pending_review'");
        $stmt->execute([$chore_id, $parent_id]);
        $chore = $stmt->fetch();

        if ($chore) {
            $stmt = $pdo->prepare("UPDATE chores SET status = 'completed', completed_at = NOW() WHERE id = ?");
            $stmt->execute([$chore_id]);

            $stmt = $pdo->prepare("UPDATE kids SET points = points + ? WHERE id = ?");
            $stmt->execute([$chore['points'], $chore['kid_id']]);

            // Update streak
            $streakResult = updateStreak($pdo, $chore['kid_id']);

            $stmt = $pdo->prepare("SELECT * FROM kids WHERE id = ?");
            $stmt->execute([$chore['kid_id']]);
            $kid = $stmt->fetch();

            if ($kid && getAgeGroup($kid['date_of_birth']) === 'teen') {
                $total_points_earned = $chore['points'] + $streakResult['bonus'];
                $earning = $total_points_earned * 0.10;
                $stmt = $pdo->prepare("INSERT INTO transactions (kid_id, type, amount, description) VALUES (?, 'chore_earning', ?, ?)");
                $stmt->execute([$chore['kid_id'], $earning, "Completed: " . $chore['title'] . ($streakResult['bonus'] > 0 ? " (+Streak Bonus)" : "")]);
                $stmt = $pdo->prepare("UPDATE kids SET savings_balance = savings_balance + ? WHERE id = ?");
                $stmt->execute([$earning, $chore['kid_id']]);
            }

            $streakMsg = $streakResult['msg'] ? " {$streakResult['msg']}" : '';
            $msg = "Chore approved! ⭐ {$chore['points']} points awarded.{$streakMsg}";
            $msg_type = 'success';
        }
        $active_tab = 'approvals';
    }

    // ── Reject Chore ──
    if ($action === 'reject_chore') {
        $chore_id = intval($_POST['chore_id'] ?? 0);
        $rejection_note = trim($_POST['rejection_note'] ?? '');
        $stmt = $pdo->prepare("UPDATE chores SET status = 'assigned', rejection_note = ? WHERE id = ? AND parent_id = ? AND status = 'pending_review'");
        $stmt->execute([$rejection_note ?: null, $chore_id, $parent_id]);
        $msg = 'Chore sent back to the kid with feedback.';
        $msg_type = 'success';
        $active_tab = 'approvals';
    }

    // ── Add Reward ──
    if ($action === 'add_reward') {
        $title = trim($_POST['reward_title'] ?? '');
        $description = trim($_POST['reward_description'] ?? '');
        $points_cost = intval($_POST['reward_points'] ?? 0);
        $age_group = $_POST['reward_age_group'] ?? 'all';
        $emoji = $_POST['reward_emoji'] ?? '🎁';

        if (empty($title) || $points_cost < 1) {
            $msg = 'Reward title and a valid point cost are required.';
            $msg_type = 'error';
        } else {
            $stmt = $pdo->prepare("INSERT INTO rewards (parent_id, title, description, points_cost, age_group, emoji) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$parent_id, $title, $description, $points_cost, $age_group, $emoji]);
            $msg = "Reward \"$title\" created!";
            $msg_type = 'success';
        }
        $active_tab = 'rewards';
    }

    // ── Delete Reward ──
    if ($action === 'delete_reward') {
        $reward_id = intval($_POST['reward_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM rewards WHERE id = ? AND parent_id = ?");
        $stmt->execute([$reward_id, $parent_id]);
        $msg = 'Reward deleted.';
        $msg_type = 'success';
        $active_tab = 'rewards';
    }

    // ── Manual Transaction (Settle Up / Add Funds) ──
    if ($action === 'manual_transaction') {
        $kid_id = intval($_POST['kid_id'] ?? 0);
        $txn_type = $_POST['txn_type'] ?? 'deduct';
        $amount = floatval($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');

        if ($amount <= 0 || empty($kid_id)) {
            $msg = 'Please select a kid and enter a valid amount.';
            $msg_type = 'error';
        } else {
            $stmt = $pdo->prepare("SELECT id, name FROM kids WHERE id = ? AND parent_id = ?");
            $stmt->execute([$kid_id, $parent_id]);
            $kid = $stmt->fetch();

            if ($kid) {
                if (empty($description)) {
                    $description = $txn_type === 'add' ? 'Manual Deposit' : 'Manual Deduction / Settle Up';
                }
                
                $actual_amount = $txn_type === 'deduct' ? -$amount : $amount;
                
                $stmt = $pdo->prepare("INSERT INTO transactions (kid_id, type, amount, description) VALUES (?, 'manual_adjustment', ?, ?)");
                $stmt->execute([$kid_id, $actual_amount, $description]);
                
                $stmt = $pdo->prepare("UPDATE kids SET savings_balance = savings_balance + ? WHERE id = ?");
                $stmt->execute([$actual_amount, $kid_id]);
                
                $action_word = $txn_type === 'add' ? 'added to' : 'deducted from';
                $msg = formatMoney($amount) . " {$action_word} {$kid['name']}'s balance.";
                $msg_type = 'success';
            } else {
                $msg = 'Invalid kid selected.';
                $msg_type = 'error';
            }
        }
        $active_tab = 'finance';
    }

    // ── Set Kid PIN ──
    if ($action === 'set_pin') {
        $kid_id = intval($_POST['kid_id'] ?? 0);
        $pin = trim($_POST['pin'] ?? '');

        if (strlen($pin) !== 4 || !ctype_digit($pin)) {
            $msg = 'PIN must be exactly 4 digits.';
            $msg_type = 'error';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM kids WHERE id = ? AND parent_id = ?");
            $stmt->execute([$kid_id, $parent_id]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("UPDATE kids SET pin = ? WHERE id = ?");
                $stmt->execute([$pin, $kid_id]);
                $msg = 'PIN set! This kid can now log in using their avatar + PIN.';
                $msg_type = 'success';
            }
        }
        $active_tab = 'overview';
    }
}

// ══════════════════════════════════════════════════════
// Fetch Data
// ══════════════════════════════════════════════════════

$kids = $pdo->prepare("SELECT * FROM kids WHERE parent_id = ? ORDER BY name");
$kids->execute([$parent_id]);
$kids_list = $kids->fetchAll();

$chores = $pdo->prepare("
    SELECT c.*, k.name as kid_name, k.avatar as kid_avatar, k.date_of_birth as kid_dob
    FROM chores c
    JOIN kids k ON c.kid_id = k.id
    WHERE c.parent_id = ?
    ORDER BY FIELD(c.status, 'pending_review', 'assigned', 'rejected', 'completed'), c.created_at DESC
");
$chores->execute([$parent_id]);
$chores_list = $chores->fetchAll();

$pending_approvals = array_filter($chores_list, fn($c) => $c['status'] === 'pending_review');

$rewards = $pdo->prepare("SELECT * FROM rewards WHERE parent_id = ? ORDER BY created_at DESC");
$rewards->execute([$parent_id]);
$rewards_list = $rewards->fetchAll();

$redemptions = $pdo->prepare("
    SELECT rr.*, r.title as reward_title, r.emoji, k.name as kid_name, k.avatar as kid_avatar
    FROM reward_redemptions rr
    JOIN rewards r ON rr.reward_id = r.id
    JOIN kids k ON rr.kid_id = k.id
    WHERE r.parent_id = ?
    ORDER BY rr.created_at DESC
    LIMIT 10
");
$redemptions->execute([$parent_id]);
$redemptions_list = $redemptions->fetchAll();

// All transactions for finance tab
$all_txns = $pdo->prepare("
    SELECT t.*, k.name as kid_name, k.avatar as kid_avatar
    FROM transactions t
    JOIN kids k ON t.kid_id = k.id
    WHERE k.parent_id = ?
    ORDER BY t.created_at DESC
    LIMIT 50
");
$all_txns->execute([$parent_id]);
$all_txns_list = $all_txns->fetchAll();

$total_kids = count($kids_list);
$pending_count = count($pending_approvals);
$assigned_chores = count(array_filter($chores_list, fn($c) => $c['status'] === 'assigned'));
$completed_chores = count(array_filter($chores_list, fn($c) => $c['status'] === 'completed'));
$total_points = array_sum(array_map(fn($k) => $k['points'], $kids_list));
$total_savings = array_sum(array_map(fn($k) => $k['savings_balance'], $kids_list));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Dashboard — ChoreQuest</title>
    <meta name="description" content="Manage your family's chores, add kids, and track progress in ChoreQuest.">
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
                        amber: { 400: '#ffd43b', 500: '#fcc419', 600: '#fab005' },
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-gray-950 text-white">

    <!-- Top Nav -->
    <nav class="sticky top-0 z-50 glass border-b border-white/5">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-3">
                    <span class="text-2xl">🏠</span>
                    <h1 class="text-xl font-black">
                        Chore<span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-400 to-purple-400">Quest</span>
                    </h1>
                </div>
                <div class="flex items-center gap-4">
                    <?php if ($pending_count > 0): ?>
                        <a href="?tab=approvals" class="relative flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-coral-600/15 border border-coral-600/25 text-coral-400 text-sm font-semibold hover:bg-coral-600/25 transition-all">
                            <div class="notification-dot"></div>
                            <?= $pending_count ?> Pending
                        </a>
                    <?php endif; ?>
                    <span class="text-sm text-gray-400 hidden sm:inline">
                        Welcome, <span class="text-white font-semibold"><?= e($parent_name) ?></span>
                    </span>
                    <a href="<?= BASE_URL ?>pages/logout.php"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-gray-400 hover:text-white hover:bg-white/10 transition-all">
                        Sign Out
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <?php if ($msg): ?>
            <div class="mb-6 p-4 rounded-xl text-sm <?= $msg_type === 'error'
                ? 'bg-coral-600/15 border border-coral-600/30 text-coral-400'
                : 'bg-emerald-600/15 border border-emerald-600/30 text-emerald-400' ?>">
                <?= $msg ?>
            </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="stat-card rounded-2xl p-5">
                <div class="text-3xl mb-2">👶</div>
                <div class="text-3xl font-black"><?= $total_kids ?></div>
                <div class="text-xs text-gray-400 uppercase tracking-wider mt-1">Kids</div>
            </div>
            <div class="stat-card rounded-2xl p-5">
                <div class="text-3xl mb-2">📋</div>
                <div class="text-3xl font-black text-amber-400"><?= $assigned_chores ?></div>
                <div class="text-xs text-gray-400 uppercase tracking-wider mt-1">Active</div>
            </div>
            <div class="stat-card rounded-2xl p-5 <?= $pending_count > 0 ? 'border-coral-500/30' : '' ?>">
                <div class="text-3xl mb-2">🔔</div>
                <div class="text-3xl font-black text-coral-400"><?= $pending_count ?></div>
                <div class="text-xs text-gray-400 uppercase tracking-wider mt-1">To Review</div>
            </div>
            <div class="stat-card rounded-2xl p-5">
                <div class="text-3xl mb-2">✅</div>
                <div class="text-3xl font-black text-mint-400"><?= $completed_chores ?></div>
                <div class="text-xs text-gray-400 uppercase tracking-wider mt-1">Completed</div>
            </div>
            <div class="stat-card rounded-2xl p-5">
                <div class="text-3xl mb-2">⭐</div>
                <div class="text-3xl font-black text-amber-500"><?= $total_points ?></div>
                <div class="text-xs text-gray-400 uppercase tracking-wider mt-1">Total Points</div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="flex gap-2 mb-8 p-1.5 rounded-xl bg-white/5 overflow-x-auto">
            <a href="?tab=overview" class="px-5 py-2.5 rounded-lg text-sm font-semibold transition-all whitespace-nowrap <?= $active_tab === 'overview' ? 'tab-active' : 'tab-inactive' ?>">👨‍👩‍👧‍👦 Family</a>
            <a href="?tab=chores" class="px-5 py-2.5 rounded-lg text-sm font-semibold transition-all whitespace-nowrap <?= $active_tab === 'chores' ? 'tab-active' : 'tab-inactive' ?>">📋 Chores</a>
            <a href="?tab=approvals" class="relative px-5 py-2.5 rounded-lg text-sm font-semibold transition-all whitespace-nowrap <?= $active_tab === 'approvals' ? 'tab-active' : 'tab-inactive' ?>">
                🔔 Approvals
                <?php if ($pending_count > 0): ?><span class="ml-1.5 px-1.5 py-0.5 rounded-full text-[10px] bg-coral-600 text-white"><?= $pending_count ?></span><?php endif; ?>
            </a>
            <a href="?tab=rewards" class="px-5 py-2.5 rounded-lg text-sm font-semibold transition-all whitespace-nowrap <?= $active_tab === 'rewards' ? 'tab-active' : 'tab-inactive' ?>">🎁 Rewards</a>
            <a href="?tab=finance" class="px-5 py-2.5 rounded-lg text-sm font-semibold transition-all whitespace-nowrap <?= $active_tab === 'finance' ? 'tab-active' : 'tab-inactive' ?>">💰 Finance</a>
        </div>

        <!-- ═══ TAB: Family Overview ═══ -->
        <?php if ($active_tab === 'overview'): ?>
        <div class="grid lg:grid-cols-3 gap-6">
            <div class="lg:col-span-1">
                <div class="glass rounded-2xl p-6">
                    <h2 class="text-lg font-bold mb-1 flex items-center gap-2"><span class="text-2xl">🧒</span> Add a Kid</h2>
                    <p class="text-gray-500 text-xs mb-5">Create a login for your child.</p>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add_kid">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Kid's Name</label>
                            <input type="text" name="kid_name" placeholder="e.g. Alex" required class="input-field w-full px-4 py-2.5 rounded-xl text-white placeholder-gray-500 text-sm outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Date of Birth</label>
                            <input type="date" name="kid_dob" required class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Username</label>
                            <input type="text" name="kid_username" placeholder="e.g. alex_hero" required class="input-field w-full px-4 py-2.5 rounded-xl text-white placeholder-gray-500 text-sm outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Password</label>
                            <input type="text" name="kid_password" placeholder="Simple & memorable" required class="input-field w-full px-4 py-2.5 rounded-xl text-white placeholder-gray-500 text-sm outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Avatar</label>
                            <div class="flex gap-2 flex-wrap" id="avatar-picker">
                                <?php
                                $avatars = ['🦸', '🧙', '🦹', '🧑‍🚀', '🧑‍🎨', '🦊', '🐱', '🐶', '🦄', '🐸', '🌟', '🚀'];
                                foreach ($avatars as $i => $av): ?>
                                    <button type="button" onclick="pickAvatar(this, '<?= $av ?>')"
                                        class="avatar-btn w-10 h-10 rounded-lg border border-white/10 flex items-center justify-center text-xl hover:bg-white/10 <?= $i === 0 ? 'selected' : '' ?>"><?= $av ?></button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="kid_avatar" id="kid_avatar" value="🦸">
                        </div>
                        <button type="submit" class="btn-primary w-full py-2.5 rounded-xl text-white font-bold text-sm">Add Kid</button>
                    </form>
                </div>
            </div>
            <div class="lg:col-span-2">
                <?php if (!empty($kids_list)): ?>
                <div class="glass rounded-2xl p-6">
                    <h2 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="text-2xl">👨‍👩‍👧‍👦</span> Your Kids</h2>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <?php foreach ($kids_list as $kid):
                            $age = calcAge($kid['date_of_birth']);
                            $ageGroup = getAgeGroup($kid['date_of_birth']);
                            $levelInfo = getLevelInfo($kid['points']);
                        ?>
                            <div class="bg-white/5 rounded-xl p-4 border border-white/5 hover:border-white/10 transition-all">
                                <div class="flex items-center gap-3">
                                    <div class="text-3xl"><?= $kid['avatar'] ?></div>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-bold text-sm"><?= e($kid['name']) ?></div>
                                        <div class="text-xs text-gray-500">@<?= e($kid['username']) ?> • Age <?= $age ?></div>
                                    </div>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $ageGroup === 'young' ? 'bg-mint-500/15 text-mint-400 border border-mint-500/20' : 'bg-amber-500/15 text-amber-400 border border-amber-500/20' ?>"><?= $ageGroup === 'young' ? '5-10' : '11-18' ?></span>
                                </div>
                                <div class="mt-3 flex items-center justify-between">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-amber-400 text-sm">⭐</span>
                                        <span class="text-amber-400 font-bold text-lg"><?= $kid['points'] ?></span>
                                        <span class="text-gray-500 text-xs ml-1">pts</span>
                                    </div>
                                    <span class="text-xs text-gray-500">Lv.<?= $levelInfo['level'] ?> <?= $levelInfo['name'] ?></span>
                                </div>
                                <?php if ($ageGroup === 'teen'): ?>
                                    <div class="mt-2 flex items-center gap-1.5">
                                        <span class="text-xs">💰</span>
                                        <span class="text-xs text-amber-400 font-semibold"><?= formatMoney($kid['savings_balance']) ?></span>
                                        <span class="text-gray-600 text-xs">savings</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($kid['current_streak'] > 0): ?>
                                    <div class="mt-2 flex items-center gap-1.5">
                                        <span class="text-xs">🔥</span>
                                        <span class="text-xs text-orange-400 font-semibold"><?= $kid['current_streak'] ?> day streak</span>
                                        <span class="text-gray-600 text-xs">(best: <?= $kid['longest_streak'] ?>)</span>
                                    </div>
                                <?php endif; ?>
                                <div class="mt-2 w-full h-1.5 rounded-full bg-white/5 overflow-hidden">
                                    <div class="progress-bar h-full rounded-full" style="width: <?= $levelInfo['progress'] ?>%"></div>
                                </div>
                                <!-- PIN Management -->
                                <div class="mt-3 pt-3 border-t border-white/5">
                                    <?php if ($kid['pin']): ?>
                                        <span class="text-xs text-mint-400">🔑 PIN login enabled</span>
                                    <?php else: ?>
                                        <form method="POST" class="flex gap-2 items-center">
                                            <input type="hidden" name="action" value="set_pin">
                                            <input type="hidden" name="kid_id" value="<?= $kid['id'] ?>">
                                            <input type="text" name="pin" placeholder="4-digit PIN" maxlength="4" pattern="[0-9]{4}" inputmode="numeric"
                                                class="input-field w-24 px-3 py-1.5 rounded-lg text-white text-xs outline-none text-center">
                                            <button type="submit" class="text-xs text-brand-400 font-semibold hover:text-brand-300">Set PIN</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="glass rounded-2xl p-12 text-center">
                    <div class="text-6xl mb-4">👨‍👩‍👧‍👦</div>
                    <h3 class="text-xl font-bold mb-2">No Kids Yet</h3>
                    <p class="text-gray-400">Add your first kid using the form on the left to get started!</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══ TAB: Chores ═══ -->
        <?php if ($active_tab === 'chores'): ?>
        <div class="grid lg:grid-cols-3 gap-6">
            <div class="lg:col-span-1">
                <div class="glass rounded-2xl p-6">
                    <h2 class="text-lg font-bold mb-1 flex items-center gap-2"><span class="text-2xl">📝</span> Assign a Chore</h2>
                    <p class="text-gray-500 text-xs mb-5">Create a new mission for a kid.</p>
                    <?php if (empty($kids_list)): ?>
                        <div class="text-center py-6 text-gray-500"><div class="text-4xl mb-2">👆</div><p class="text-sm">Add a kid first to assign chores.</p></div>
                    <?php else: ?>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="assign_chore">
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Assign To</label>
                                <select name="kid_id" required class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none appearance-none cursor-pointer">
                                    <option value="">Select a kid...</option>
                                    <?php foreach ($kids_list as $kid): ?>
                                        <option value="<?= $kid['id'] ?>"><?= $kid['avatar'] ?> <?= e($kid['name']) ?> (<?= getAgeGroup($kid['date_of_birth']) === 'young' ? '5-10' : '11-18' ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Chore Title</label>
                                <input type="text" name="chore_title" placeholder="e.g. Clean your room" required class="input-field w-full px-4 py-2.5 rounded-xl text-white placeholder-gray-500 text-sm outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Description <span class="text-gray-600">(optional)</span></label>
                                <textarea name="chore_description" placeholder="Any extra details..." rows="2" class="input-field w-full px-4 py-2.5 rounded-xl text-white placeholder-gray-500 text-sm outline-none resize-none"></textarea>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Points</label>
                                    <input type="number" name="chore_points" value="10" min="1" max="100" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Due Date</label>
                                    <input type="date" name="due_date" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none">
                                </div>
                            </div>
                            <button type="submit" class="btn-primary w-full py-2.5 rounded-xl text-white font-bold text-sm">Assign Chore</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="lg:col-span-2">
                <div class="glass rounded-2xl p-6">
                    <h2 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="text-2xl">📋</span> All Chores</h2>
                    <?php $filtered_chores = array_filter($chores_list, fn($c) => in_array($c['status'], ['assigned', 'completed', 'rejected']));
                    if (empty($filtered_chores)): ?>
                        <div class="text-center py-12 text-gray-500"><div class="text-5xl mb-3">🎯</div><p class="text-sm">No chores assigned yet.</p></div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($filtered_chores as $chore):
                                $statusColors = ['assigned' => 'bg-amber-500/15 text-amber-400', 'completed' => 'bg-emerald-500/15 text-emerald-400', 'rejected' => 'bg-coral-500/15 text-coral-400'];
                                $statusIcons = ['assigned' => '⏳', 'completed' => '✅', 'rejected' => '↩️'];
                            ?>
                                <div class="chore-row rounded-xl p-4 bg-white/[0.02] flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="text-2xl flex-shrink-0"><?= $statusIcons[$chore['status']] ?? '⏳' ?></div>
                                        <div class="min-w-0">
                                            <div class="font-semibold text-sm <?= $chore['status'] === 'completed' ? 'line-through text-gray-500' : '' ?>"><?= e($chore['title']) ?></div>
                                            <div class="text-xs text-gray-500 flex items-center gap-2 mt-0.5 flex-wrap">
                                                <span><?= $chore['kid_avatar'] ?> <?= e($chore['kid_name']) ?></span><span>•</span>
                                                <span class="text-amber-400">⭐ <?= $chore['points'] ?> pts</span>
                                                <?php if ($chore['due_date']): ?><span>•</span><span>📅 <?= formatShortDate($chore['due_date']) ?></span><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <span class="px-2.5 py-1 rounded-lg text-xs font-semibold <?= $statusColors[$chore['status']] ?? '' ?>"><?= ucfirst(str_replace('_', ' ', $chore['status'])) ?></span>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this chore?')">
                                            <input type="hidden" name="action" value="delete_chore">
                                            <input type="hidden" name="chore_id" value="<?= $chore['id'] ?>">
                                            <button type="submit" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-600 hover:text-coral-400 hover:bg-coral-500/10 transition-all text-sm">🗑️</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══ TAB: Approvals ═══ -->
        <?php if ($active_tab === 'approvals'): ?>
        <div class="glass rounded-2xl p-6">
            <h2 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="text-2xl">🔔</span> Pending Approvals
                <?php if ($pending_count > 0): ?><span class="ml-2 px-2.5 py-0.5 rounded-full text-xs font-bold bg-coral-500/15 text-coral-400"><?= $pending_count ?></span><?php endif; ?>
            </h2>
            <?php if (empty($pending_approvals)): ?>
                <div class="text-center py-12 text-gray-500"><div class="text-5xl mb-3">🎉</div><p class="text-sm">All caught up! No chores waiting for review.</p></div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($pending_approvals as $chore): ?>
                        <div class="bg-white/[0.03] rounded-xl p-5 border border-amber-500/10 hover:border-amber-500/20 transition-all">
                            <div class="flex flex-col sm:flex-row sm:items-start gap-4">
                                <?php if ($chore['proof_photo']): ?>
                                    <div class="flex-shrink-0">
                                        <img src="<?= BASE_URL . e($chore['proof_photo']) ?>" alt="Proof" class="proof-thumb" onclick="window.open(this.src, '_blank')">
                                    </div>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-xl"><?= $chore['kid_avatar'] ?></span>
                                        <span class="font-bold text-sm"><?= e($chore['kid_name']) ?></span>
                                        <span class="text-gray-600">submitted:</span>
                                    </div>
                                    <h3 class="font-bold text-base mb-1"><?= e($chore['title']) ?></h3>
                                    <?php if ($chore['description']): ?><p class="text-gray-400 text-sm"><?= e($chore['description']) ?></p><?php endif; ?>
                                    <div class="flex items-center gap-3 mt-2 text-xs text-gray-500">
                                        <span class="text-amber-400 font-semibold">⭐ <?= $chore['points'] ?> pts</span>
                                        <?php if ($chore['due_date']): ?><span>📅 <?= formatShortDate($chore['due_date']) ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex sm:flex-col gap-2 flex-shrink-0">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="approve_chore">
                                        <input type="hidden" name="chore_id" value="<?= $chore['id'] ?>">
                                        <button type="submit" class="btn-success px-5 py-2.5 rounded-xl text-white font-bold text-xs uppercase tracking-wider">✅ Approve</button>
                                    </form>
                                    <button type="button" onclick="toggleReject(<?= $chore['id'] ?>)" class="px-5 py-2.5 rounded-xl text-coral-400 font-bold text-xs uppercase tracking-wider border border-coral-500/20 hover:bg-coral-500/10 transition-all">❌ Reject</button>
                                </div>
                            </div>
                            <div id="reject-<?= $chore['id'] ?>" style="display: none;" class="mt-4 pt-4 border-t border-white/5">
                                <form method="POST" class="flex gap-3">
                                    <input type="hidden" name="action" value="reject_chore">
                                    <input type="hidden" name="chore_id" value="<?= $chore['id'] ?>">
                                    <input type="text" name="rejection_note" placeholder="Feedback for the kid (optional)..." class="input-field flex-1 px-4 py-2.5 rounded-xl text-white placeholder-gray-500 text-sm outline-none">
                                    <button type="submit" class="btn-danger px-5 py-2.5 rounded-xl text-white font-bold text-xs uppercase tracking-wider">Send Back</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ═══ TAB: Rewards ═══ -->
        <?php if ($active_tab === 'rewards'): ?>
        <div class="grid lg:grid-cols-3 gap-6">
            <div class="lg:col-span-1">
                <div class="glass rounded-2xl p-6">
                    <h2 class="text-lg font-bold mb-1 flex items-center gap-2"><span class="text-2xl">🎁</span> Create Reward</h2>
                    <p class="text-gray-500 text-xs mb-5">Define rewards kids can redeem with points.</p>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add_reward">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Reward Title</label>
                            <input type="text" name="reward_title" placeholder="e.g. McDonald's Treat" required class="input-field w-full px-4 py-2.5 rounded-xl text-white placeholder-gray-500 text-sm outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Description <span class="text-gray-600">(optional)</span></label>
                            <textarea name="reward_description" placeholder="Details about this reward..." rows="2" class="input-field w-full px-4 py-2.5 rounded-xl text-white placeholder-gray-500 text-sm outline-none resize-none"></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Points Cost</label>
                                <input type="number" name="reward_points" value="50" min="1" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Age Group</label>
                                <select name="reward_age_group" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none appearance-none cursor-pointer">
                                    <option value="all">All Ages</option>
                                    <option value="young">5-10 (Treats)</option>
                                    <option value="teen">11-18 (Money)</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Emoji Icon</label>
                            <div class="flex gap-2 flex-wrap" id="reward-emoji-picker">
                                <?php $rewardEmojis = ['🎁', '🍔', '🍕', '🎮', '📱', '🏆', '🎟️', '💰', '🧸', '🎬', '🍦', '⚽'];
                                foreach ($rewardEmojis as $i => $rem): ?>
                                    <button type="button" onclick="pickRewardEmoji(this, '<?= $rem ?>')"
                                        class="avatar-btn w-10 h-10 rounded-lg border border-white/10 flex items-center justify-center text-xl hover:bg-white/10 <?= $i === 0 ? 'selected' : '' ?>"><?= $rem ?></button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="reward_emoji" id="reward_emoji" value="🎁">
                        </div>
                        <button type="submit" class="btn-primary w-full py-2.5 rounded-xl text-white font-bold text-sm">Create Reward</button>
                    </form>
                </div>
            </div>
            <div class="lg:col-span-2 space-y-6">
                <div class="glass rounded-2xl p-6">
                    <h2 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="text-2xl">🏪</span> Active Rewards</h2>
                    <?php if (empty($rewards_list)): ?>
                        <div class="text-center py-12 text-gray-500"><div class="text-5xl mb-3">🎁</div><p class="text-sm">No rewards created yet.</p></div>
                    <?php else: ?>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <?php foreach ($rewards_list as $reward):
                                $groupLabel = ['all' => 'All Ages', 'young' => '5-10', 'teen' => '11-18'][$reward['age_group']];
                                $groupColor = ['all' => 'bg-brand-500/15 text-brand-400', 'young' => 'bg-mint-500/15 text-mint-400', 'teen' => 'bg-amber-500/15 text-amber-400'][$reward['age_group']];
                            ?>
                                <div class="reward-card rounded-xl p-4">
                                    <div class="flex items-start justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="text-3xl"><?= $reward['emoji'] ?></div>
                                            <div>
                                                <div class="font-bold text-sm"><?= e($reward['title']) ?></div>
                                                <?php if ($reward['description']): ?><div class="text-xs text-gray-500 mt-0.5"><?= e($reward['description']) ?></div><?php endif; ?>
                                            </div>
                                        </div>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this reward?')">
                                            <input type="hidden" name="action" value="delete_reward">
                                            <input type="hidden" name="reward_id" value="<?= $reward['id'] ?>">
                                            <button type="submit" class="text-gray-600 hover:text-coral-400 transition-colors text-sm">🗑️</button>
                                        </form>
                                    </div>
                                    <div class="flex items-center gap-2 mt-3">
                                        <span class="text-amber-400 font-bold text-sm">⭐ <?= $reward['points_cost'] ?> pts</span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $groupColor ?>"><?= $groupLabel ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($redemptions_list)): ?>
                <div class="glass rounded-2xl p-6">
                    <h2 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="text-2xl">📜</span> Recent Redemptions</h2>
                    <div class="space-y-2">
                        <?php foreach ($redemptions_list as $r): ?>
                            <div class="flex items-center justify-between p-3 rounded-lg bg-white/[0.02] border border-white/5">
                                <div class="flex items-center gap-3">
                                    <span class="text-xl"><?= $r['kid_avatar'] ?></span>
                                    <div>
                                        <span class="text-sm font-semibold"><?= e($r['kid_name']) ?></span>
                                        <span class="text-gray-500 text-sm"> redeemed </span>
                                        <span class="text-sm"><?= $r['emoji'] ?> <?= e($r['reward_title']) ?></span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs text-coral-400 font-semibold">-<?= $r['points_spent'] ?> pts</div>
                                    <div class="text-xs text-gray-600"><?= formatDate($r['created_at'], 'M j, g:ia') ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══ TAB: Finance ═══ -->
        <?php if ($active_tab === 'finance'): ?>
        <div class="grid lg:grid-cols-3 gap-6">
            <!-- Per-Kid Financial Overview -->
            <div class="lg:col-span-1 space-y-4">
                <div class="glass rounded-2xl p-6">
                    <h2 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="text-2xl">💰</span> Financial Overview</h2>

                    <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 mb-4 text-center">
                        <div class="text-2xl font-black text-amber-400"><?= formatMoney($total_savings) ?></div>
                        <div class="text-xs text-gray-400 uppercase tracking-wider mt-1">Total Family Savings</div>
                    </div>

                    <?php foreach ($kids_list as $kid):
                        $kidAgeGroup = getAgeGroup($kid['date_of_birth']);
                    ?>
                        <div class="rounded-xl p-4 bg-white/[0.03] border border-white/5 mb-3">
                            <div class="flex items-center gap-3 mb-2">
                                <span class="text-2xl"><?= $kid['avatar'] ?></span>
                                <div class="flex-1">
                                    <div class="font-bold text-sm"><?= e($kid['name']) ?></div>
                                    <div class="text-xs text-gray-500"><?= $kidAgeGroup === 'young' ? 'Treats Group' : 'Savings Group' ?></div>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-lg bg-white/[0.03] p-2">
                                    <div class="text-sm font-bold text-amber-400">⭐ <?= $kid['points'] ?></div>
                                    <div class="text-[10px] text-gray-500">Points</div>
                                </div>
                                <div class="rounded-lg bg-white/[0.03] p-2">
                                    <div class="text-sm font-bold text-orange-400">🔥 <?= $kid['current_streak'] ?></div>
                                    <div class="text-[10px] text-gray-500">Streak</div>
                                </div>
                                <div class="rounded-lg bg-white/[0.03] p-2">
                                    <div class="text-sm font-bold text-mint-400"><?= formatMoney($kid['savings_balance']) ?></div>
                                    <div class="text-[10px] text-gray-500">Balance</div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Adjust Balance Form -->
                <div class="glass rounded-2xl p-6 mt-4">
                    <h2 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="text-2xl">✍️</span> Adjust Balance</h2>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="manual_transaction">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Select Kid</label>
                            <select name="kid_id" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none" required>
                                <option value="">-- Choose Kid --</option>
                                <?php foreach ($kids_list as $kid): ?>
                                    <option value="<?= $kid['id'] ?>"><?= e($kid['name']) ?> (<?= formatMoney($kid['savings_balance']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Action</label>
                                <select name="txn_type" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none">
                                    <option value="deduct">Deduct (Settle Up)</option>
                                    <option value="add">Add (Deposit)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Amount</label>
                                <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Description</label>
                            <input type="text" name="description" placeholder="e.g. Paid out in cash" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none">
                        </div>
                        <button type="submit" onclick="return confirm('Are you sure you want to adjust this balance?')" class="btn-primary w-full py-2.5 rounded-xl text-white font-bold text-sm uppercase tracking-wider">Submit Adjustment</button>
                    </form>
                </div>
            </div>

            <!-- Transaction History -->
            <div class="lg:col-span-2">
                <div class="glass rounded-2xl p-6">
                    <h2 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="text-2xl">📜</span> Transaction History</h2>

                    <?php if (empty($all_txns_list)): ?>
                        <div class="text-center py-12 text-gray-500">
                            <div class="text-5xl mb-3">📊</div>
                            <p class="text-sm">No transactions yet. Transactions appear when teen kids earn or spend money.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($all_txns_list as $txn):
                                $typeIcons = ['chore_earning' => '✅', 'reward_redemption' => '🎁', 'savings_deposit' => '🔒', 'savings_withdrawal' => '💰', 'manual_adjustment' => '✍️'];
                                $typeLabels = ['chore_earning' => 'Chore Earning', 'reward_redemption' => 'Redemption', 'savings_deposit' => 'Vault Deposit', 'savings_withdrawal' => 'Vault Withdrawal', 'manual_adjustment' => 'Manual Adjustment'];
                                $isPositive = $txn['amount'] > 0;
                            ?>
                                <div class="flex items-center justify-between p-3 rounded-lg bg-white/[0.02] border border-white/5 hover:border-white/10 transition-all">
                                    <div class="flex items-center gap-3">
                                        <span class="text-lg"><?= $txn['kid_avatar'] ?></span>
                                        <div>
                                            <div class="text-sm font-semibold">
                                                <span class="text-gray-300"><?= e($txn['kid_name']) ?></span>
                                                <span class="text-gray-600 mx-1">•</span>
                                                <span class="text-xs px-2 py-0.5 rounded-full <?= $isPositive ? 'bg-mint-500/10 text-mint-400' : 'bg-coral-500/10 text-coral-400' ?>">
                                                    <?= $typeLabels[$txn['type']] ?? $txn['type'] ?>
                                                </span>
                                            </div>
                                            <div class="text-xs text-gray-500 mt-0.5"><?= e($txn['description']) ?></div>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <div class="text-sm font-bold <?= $isPositive ? 'text-mint-400' : 'text-coral-400' ?>">
                                            <?= $isPositive ? '+' : '' ?><?= formatMoney($txn['amount']) ?>
                                        </div>
                                        <div class="text-[10px] text-gray-600"><?= formatDate($txn['created_at'], 'M j, g:ia') ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </main>

    <!-- Mobile Bottom Navigation -->
    <nav class="mobile-nav">
        <a href="?tab=overview" class="mobile-nav-item <?= $active_tab === 'overview' ? 'active' : '' ?>">
            <span class="icon">👨‍👩‍👧</span>
            <span>Family</span>
        </a>
        <a href="?tab=chores" class="mobile-nav-item <?= $active_tab === 'chores' ? 'active' : '' ?>">
            <span class="icon">📋</span>
            <span>Chores</span>
        </a>
        <a href="?tab=approvals" class="mobile-nav-item relative <?= $active_tab === 'approvals' ? 'active' : '' ?>">
            <span class="icon">✅</span>
            <span>Approve</span>
            <?php if ($pending_count > 0): ?>
                <span class="absolute top-1 right-2 w-3 h-3 bg-coral-500 rounded-full border-2 border-gray-900"></span>
            <?php endif; ?>
        </a>
        <a href="?tab=rewards" class="mobile-nav-item <?= $active_tab === 'rewards' ? 'active' : '' ?>">
            <span class="icon">🎁</span>
            <span>Rewards</span>
        </a>
        <a href="?tab=finance" class="mobile-nav-item <?= $active_tab === 'finance' ? 'active' : '' ?>">
            <span class="icon">💰</span>
            <span>Finance</span>
        </a>
    </nav>

    <script>
        function pickAvatar(btn, emoji) {
            document.querySelectorAll('#avatar-picker .avatar-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            document.getElementById('kid_avatar').value = emoji;
        }
        function pickRewardEmoji(btn, emoji) {
            document.querySelectorAll('#reward-emoji-picker .avatar-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            document.getElementById('reward_emoji').value = emoji;
        }
        function toggleReject(choreId) {
            const el = document.getElementById('reject-' + choreId);
            el.style.display = el.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</body>
</html>
