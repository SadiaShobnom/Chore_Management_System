<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireKid();

$kid_id = getKidId();
$kid_name = $_SESSION['kid_name'];
$kid_avatar = $_SESSION['kid_avatar'] ?? '🦸';

$msg = '';
$msg_type = '';

// ══════════════════════════════════════════════════════
// Handle POST actions
// ══════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Mark Chore as Done (→ pending_review) ──
    if ($action === 'submit_chore') {
        $chore_id = intval($_POST['chore_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM chores WHERE id = ? AND kid_id = ? AND status = 'assigned'");
        $stmt->execute([$chore_id, $kid_id]);
        $chore = $stmt->fetch();

        if ($chore) {
            $proof_path = null;
            if (isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $proof_path = uploadProofPhoto($_FILES['proof_photo']);
                if ($proof_path === false) {
                    $msg = 'Photo upload failed. Only JPG, PNG, GIF, WebP under 10MB are allowed.';
                    $msg_type = 'error';
                }
            }

            if ($msg_type !== 'error') {
                $stmt = $pdo->prepare("UPDATE chores SET status = 'pending_review', proof_photo = ? WHERE id = ?");
                $stmt->execute([$proof_path, $chore_id]);

                $msg = "📤 \"{$chore['title']}\" submitted for approval! Your parent will review it.";
                $msg_type = 'success';
            }
        } else {
            $msg = 'This chore is not available.';
            $msg_type = 'error';
        }
    }

    // ── Redeem Reward ──
    if ($action === 'redeem_reward') {
        $reward_id = intval($_POST['reward_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM kids WHERE id = ?");
        $stmt->execute([$kid_id]);
        $kidData = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT * FROM rewards WHERE id = ? AND parent_id = ?");
        $stmt->execute([$reward_id, $kidData['parent_id']]);
        $reward = $stmt->fetch();

        if ($reward) {
            $kidAgeGroup = getAgeGroup($kidData['date_of_birth']);
            if ($reward['age_group'] !== 'all' && $reward['age_group'] !== $kidAgeGroup) {
                $msg = "This reward isn't available for your age group.";
                $msg_type = 'error';
            } elseif ($kidData['points'] < $reward['points_cost']) {
                $msg = "Not enough points! You need " . ($reward['points_cost'] - $kidData['points']) . " more.";
                $msg_type = 'error';
            } else {
                // Determine if we are claiming the target reward
                $clearTarget = false;
                if ($kidData['target_reward_id'] == $reward_id) {
                    $clearTarget = true;
                }

                $stmt = $pdo->prepare("UPDATE kids SET points = points - ? " . ($clearTarget ? ", target_reward_id = NULL" : "") . " WHERE id = ?");
                $stmt->execute([$reward['points_cost'], $kid_id]);

                $stmt = $pdo->prepare("INSERT INTO reward_redemptions (kid_id, reward_id, points_spent) VALUES (?, ?, ?)");
                $stmt->execute([$kid_id, $reward_id, $reward['points_cost']]);

                if ($kidAgeGroup === 'teen') {
                    $cost = $reward['points_cost'] * 0.10;
                    $stmt = $pdo->prepare("INSERT INTO transactions (kid_id, type, amount, description) VALUES (?, 'reward_redemption', ?, ?)");
                    $stmt->execute([$kid_id, -$cost, "Redeemed: " . $reward['title']]);
                }

                $msg = "🎉 You redeemed \"{$reward['title']}\"! Tell your parent to claim your reward!";
                $msg_type = 'success';
            }
        }
    }

    // ── Set Target Reward ──
    if ($action === 'set_target_reward') {
        $reward_id = intval($_POST['reward_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE kids SET target_reward_id = ? WHERE id = ?");
        $stmt->execute([$reward_id ?: null, $kid_id]);
        $msg = "🎯 Target set! Keep earning points to reach it!";
        $msg_type = 'success';
    }

    // ── Savings Deposit ──
    if ($action === 'savings_deposit') {
        $amount = floatval($_POST['deposit_amount'] ?? 0);
        $lock_months = intval($_POST['lock_months'] ?? 3);

        if (!in_array($lock_months, [3, 6, 9])) {
            $msg = 'Invalid lock period.';
            $msg_type = 'error';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM kids WHERE id = ?");
            $stmt->execute([$kid_id]);
            $kidData = $stmt->fetch();

            if ($amount <= 0 || $amount > $kidData['savings_balance']) {
                $msg = 'Invalid deposit amount. You can only deposit from your available balance.';
                $msg_type = 'error';
            } else {
                $stmt = $pdo->prepare("UPDATE kids SET savings_balance = savings_balance - ? WHERE id = ?");
                $stmt->execute([$amount, $kid_id]);

                $unlocks_at = date('Y-m-d H:i:s', strtotime("+{$lock_months} months"));
                $stmt = $pdo->prepare("INSERT INTO savings_goals (kid_id, amount, lock_months, unlocks_at) VALUES (?, ?, ?, ?)");
                $stmt->execute([$kid_id, $amount, $lock_months, $unlocks_at]);

                $stmt = $pdo->prepare("INSERT INTO transactions (kid_id, type, amount, description) VALUES (?, 'savings_deposit', ?, ?)");
                $stmt->execute([$kid_id, -$amount, "Locked {$lock_months} months"]);

                $msg = "💰 " . formatMoney($amount) . " locked for {$lock_months} months! Keep earning!";
                $msg_type = 'success';
            }
        }
    }

    // ── Savings Withdrawal ──
    if ($action === 'savings_withdraw') {
        $goal_id = intval($_POST['goal_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM savings_goals WHERE id = ? AND kid_id = ? AND status = 'locked'");
        $stmt->execute([$goal_id, $kid_id]);
        $goal = $stmt->fetch();

        if ($goal && strtotime($goal['unlocks_at']) <= time()) {
            $stmt = $pdo->prepare("UPDATE kids SET savings_balance = savings_balance + ? WHERE id = ?");
            $stmt->execute([$goal['amount'], $kid_id]);

            $stmt = $pdo->prepare("UPDATE savings_goals SET status = 'withdrawn' WHERE id = ?");
            $stmt->execute([$goal_id]);

            $stmt = $pdo->prepare("INSERT INTO transactions (kid_id, type, amount, description) VALUES (?, 'savings_withdrawal', ?, ?)");
            $stmt->execute([$kid_id, $goal['amount'], "Savings unlocked"]);

            $msg = "🎉 " . formatMoney($goal['amount']) . " has been added back to your balance!";
            $msg_type = 'success';
        } else {
            $msg = "This savings goal hasn't unlocked yet!";
            $msg_type = 'error';
        }
    }

    // ── Record Spending ──
    if ($action === 'record_spending') {
        $amount = floatval($_POST['spend_amount'] ?? 0);
        $description = trim($_POST['spend_description'] ?? 'Personal Spending');

        if ($amount <= 0) {
            $msg = 'Please enter a valid amount to spend.';
            $msg_type = 'error';
        } else {
            $stmt = $pdo->prepare("SELECT savings_balance FROM kids WHERE id = ?");
            $stmt->execute([$kid_id]);
            $current_balance = $stmt->fetchColumn();

            if ($amount > $current_balance) {
                $msg = 'You do not have enough money in your available balance!';
                $msg_type = 'error';
            } else {
                $stmt = $pdo->prepare("UPDATE kids SET savings_balance = savings_balance - ? WHERE id = ?");
                $stmt->execute([$amount, $kid_id]);

                $stmt = $pdo->prepare("INSERT INTO transactions (kid_id, type, amount, description) VALUES (?, 'manual_adjustment', ?, ?)");
                $stmt->execute([$kid_id, -$amount, "Spent: " . $description]);

                $msg = "Recorded spending of " . formatMoney($amount) . ". Balance updated!";
                $msg_type = 'success';
            }
        }
    }
}

// ══════════════════════════════════════════════════════
// Fetch kid data (refreshed)
// ══════════════════════════════════════════════════════

$stmt = $pdo->prepare("SELECT * FROM kids WHERE id = ?");
$stmt->execute([$kid_id]);
$kid = $stmt->fetch();
$kid_points = $kid['points'];
$kid_avatar = $kid['avatar'];
$kid_dob = $kid['date_of_birth'];
$kid_age = calcAge($kid_dob);
$kid_age_group = getAgeGroup($kid_dob);
$kid_savings = $kid['savings_balance'];
$kid_streak = $kid['current_streak'];
$kid_target = $kid['target_reward_id'];
$levelInfo = getLevelInfo($kid_points);

$stmt = $pdo->prepare("
    SELECT * FROM chores WHERE kid_id = ?
    ORDER BY FIELD(status, 'assigned', 'pending_review', 'rejected', 'completed'), created_at DESC
");
$stmt->execute([$kid_id]);
$chores_list = $stmt->fetchAll();

$assigned = array_filter($chores_list, fn($c) => $c['status'] === 'assigned');
$pending_review = array_filter($chores_list, fn($c) => $c['status'] === 'pending_review');
$rejected = array_filter($chores_list, fn($c) => $c['status'] === 'rejected');
$completed = array_filter($chores_list, fn($c) => $c['status'] === 'completed');

$stmt = $pdo->prepare("SELECT * FROM rewards WHERE parent_id = ? AND (age_group = 'all' OR age_group = ?) ORDER BY points_cost ASC");
$stmt->execute([$kid['parent_id'], $kid_age_group]);
$rewards_list = $stmt->fetchAll();

$target_reward = null;
if ($kid_target) {
    foreach ($rewards_list as $r) {
        if ($r['id'] == $kid_target) {
            $target_reward = $r;
            break;
        }
    }
}

$savings_goals = [];
if ($kid_age_group === 'teen') {
    $stmt = $pdo->prepare("SELECT * FROM savings_goals WHERE kid_id = ? AND status IN ('locked', 'unlocked') ORDER BY unlocks_at ASC");
    $stmt->execute([$kid_id]);
    $savings_goals = $stmt->fetchAll();

    foreach ($savings_goals as &$sg) {
        if ($sg['status'] === 'locked' && strtotime($sg['unlocks_at']) <= time()) {
            $pdo->prepare("UPDATE savings_goals SET status = 'unlocked' WHERE id = ?")->execute([$sg['id']]);
            $sg['status'] = 'unlocked';
        }
    }
    unset($sg);
}

$transactions = [];
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE kid_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$kid_id]);
$transactions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mission Board — ChoreQuest</title>
    <meta name="description" content="Your ChoreQuest mission board. Complete chores, earn points, level up!">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/shared.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: { 50: '#f0f4ff', 100: '#dbe4ff', 200: '#bac8ff', 300: '#91a7ff', 400: '#748ffc', 500: '#5c7cfa', 600: '#4c6ef5', 700: '#4263eb', 800: '#3b5bdb', 900: '#364fc7' },
                        mint: { 50: '#e6fcf5', 100: '#c3fae8', 200: '#96f2d7', 300: '#63e6be', 400: '#38d9a9', 500: '#20c997' },
                        coral: { 400: '#ff8787', 500: '#ff6b6b', 600: '#fa5252' },
                        amber: { 400: '#ffd43b', 500: '#fcc419', 600: '#fab005' },
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-gray-950 text-white">

    <nav class="sticky top-0 z-50 glass border-b border-white/5">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-3">
                    <span class="text-2xl">🏠</span>
                    <h1 class="text-xl font-black">Chore<span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-400 to-purple-400">Quest</span></h1>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/20">
                        <span class="text-amber-400">⭐</span>
                        <span class="text-amber-400 font-bold text-sm"><?= $kid_points ?></span>
                    </div>
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-mint-500/10 border border-mint-500/20">
                        <span class="text-mint-400">💰</span>
                        <span class="text-mint-400 font-bold text-sm"><?= formatMoney($kid_savings) ?></span>
                    </div>
                    <a href="<?= BASE_URL ?>pages/logout.php" class="px-4 py-2 rounded-lg text-sm font-medium text-gray-400 hover:text-white hover:bg-white/10 transition-all">Sign Out</a>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-5xl mx-auto px-4 sm:px-6 py-8">

        <?php if ($msg): ?>
            <div class="mb-6 p-4 rounded-xl text-sm bounce-in <?= $msg_type === 'error' ? 'bg-coral-600/15 border border-coral-600/30 text-coral-400' : 'bg-emerald-600/15 border border-emerald-600/30 text-emerald-400' ?>"><?= $msg ?></div>
        <?php endif; ?>

        <!-- Hero Section -->
        <div class="hero-gradient rounded-2xl p-8 mb-8 border border-white/5">
            <div class="flex flex-col sm:flex-row items-center gap-6">
                <div class="text-7xl bounce-in"><?= $kid_avatar ?></div>
                <div class="text-center sm:text-left flex-1">
                    <h2 class="text-3xl font-black mb-1">Welcome, <?= e($kid_name) ?>!</h2>
                    <p class="text-gray-400 mb-4"><?= $kid_age_group === 'young' ? "You're doing an amazing job, hero! Keep going! 🚀" : "Keep earning and saving! You're building great habits! 💪" ?></p>
                    <div class="flex items-center gap-3 mb-2 flex-wrap justify-center sm:justify-start">
                        <span class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-brand-600/20 text-brand-400 border border-brand-500/20">Level <?= $levelInfo['level'] ?> — <?= $levelInfo['name'] ?></span>
                        <span class="text-xs text-gray-500"><?= $kid_points ?> / <?= $levelInfo['nextThreshold'] < 999999 ? $levelInfo['nextThreshold'] : '∞' ?> pts</span>
                        <?php if ($kid_streak > 0): ?>
                            <span class="px-3 py-1 rounded-full text-xs font-bold tracking-wider bg-orange-500/20 text-orange-400 border border-orange-500/20">🔥 <?= $kid_streak ?> Day Streak</span>
                        <?php endif; ?>
                    </div>
                    <div class="w-full h-2.5 rounded-full bg-white/5 overflow-hidden">
                        <div class="progress-bar h-full rounded-full transition-all duration-1000" style="width: <?= $levelInfo['progress'] ?>%"></div>
                    </div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-black text-amber-400"><?= $kid_points ?></div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider">Total Points</div>
                </div>
            </div>
        </div>

        <!-- Target Treat Tracker (Young Kids) -->
        <?php if ($kid_age_group === 'young' && $target_reward):
            $targetProgress = min(100, ($kid_points / max(1, $target_reward['points_cost'])) * 100);
            $pointsNeeded = max(0, $target_reward['points_cost'] - $kid_points);
        ?>
        <div class="glass rounded-2xl p-6 mb-8 border border-mint-500/20 shadow-[0_0_15px_rgba(32,201,151,0.1)]">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2"><span class="text-2xl">🎯</span> Target Treat Goal</h3>
            <div class="flex items-center gap-4">
                <div class="text-5xl bounce-in"><?= $target_reward['emoji'] ?></div>
                <div class="flex-1 min-w-0">
                    <div class="flex justify-between items-end mb-2">
                        <div>
                            <h4 class="font-bold text-base"><?= e($target_reward['title']) ?></h4>
                            <div class="text-sm text-gray-400"><?= $pointsNeeded > 0 ? "You need {$pointsNeeded} more points!" : "🎉 You reached your goal!" ?></div>
                        </div>
                        <div class="text-amber-400 font-bold hidden sm:block">⭐ <?= $kid_points ?> / <?= $target_reward['points_cost'] ?></div>
                    </div>
                    <div class="w-full h-4 rounded-full bg-white/5 overflow-hidden border border-white/5 relative">
                        <div class="h-full rounded-full transition-all duration-1000 <?= $pointsNeeded === 0 ? 'bg-mint-400' : 'progress-bar' ?>" style="width: <?= $targetProgress ?>%"></div>
                        <?php if ($pointsNeeded === 0): ?>
                            <div class="absolute inset-0 flex items-center justify-center text-[10px] font-black text-gray-900 uppercase tracking-wider mix-blend-overlay">Goal Reached!</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($pointsNeeded === 0): ?>
                    <form method="POST" class="hidden sm:block">
                        <input type="hidden" name="action" value="redeem_reward">
                        <input type="hidden" name="reward_id" value="<?= $target_reward['id'] ?>">
                        <button type="submit" class="btn-primary px-4 py-2 rounded-xl text-white font-bold text-sm uppercase tracking-wider">Redeem Now</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($pointsNeeded === 0): ?>
                <form method="POST" class="mt-4 sm:hidden">
                    <input type="hidden" name="action" value="redeem_reward">
                    <input type="hidden" name="reward_id" value="<?= $target_reward['id'] ?>">
                    <button type="submit" class="btn-primary w-full py-2.5 rounded-xl text-white font-bold text-sm uppercase tracking-wider">Redeem Now</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Rejected / Sent Back -->
        <?php if (!empty($rejected)): ?>
        <div class="mb-8">
            <h3 class="text-xl font-bold mb-4 flex items-center gap-2"><span class="text-2xl">↩️</span> Sent Back <span class="ml-2 px-2.5 py-0.5 rounded-full text-xs font-bold bg-coral-500/15 text-coral-400"><?= count($rejected) ?></span></h3>
            <div class="grid sm:grid-cols-2 gap-4">
                <?php foreach ($rejected as $chore): ?>
                    <div class="mission-card rounded-2xl p-5 border-coral-500/20">
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1 min-w-0">
                                <h4 class="font-bold text-base"><?= e($chore['title']) ?></h4>
                                <?php if ($chore['rejection_note']): ?>
                                    <p class="text-coral-400 text-sm mt-1 bg-coral-500/10 px-3 py-1.5 rounded-lg">💬 <?= e($chore['rejection_note']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-500/10 ml-3 flex-shrink-0">
                                <span class="text-amber-400 text-xs">⭐</span><span class="text-amber-400 font-bold text-sm"><?= $chore['points'] ?></span>
                            </div>
                        </div>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="submit_chore">
                            <input type="hidden" name="chore_id" value="<?= $chore['id'] ?>">
                            <div class="flex items-center gap-2">
                                <label class="flex-1 cursor-pointer">
                                    <div class="input-field px-3 py-2 rounded-xl text-xs text-gray-400 flex items-center gap-2">📷 <span>Add photo proof</span></div>
                                    <input type="file" name="proof_photo" accept="image/*" class="hidden">
                                </label>
                                <button type="submit" onclick="celebrateComplete(event)" class="btn-success px-4 py-2 rounded-xl text-white font-bold text-xs uppercase tracking-wider">✓ Resubmit</button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Awaiting Approval -->
        <?php if (!empty($pending_review)): ?>
        <div class="mb-8">
            <h3 class="text-xl font-bold mb-4 flex items-center gap-2"><span class="text-2xl">⏳</span> Awaiting Approval <span class="ml-2 px-2.5 py-0.5 rounded-full text-xs font-bold bg-brand-500/15 text-brand-400"><?= count($pending_review) ?></span></h3>
            <div class="grid sm:grid-cols-2 gap-4">
                <?php foreach ($pending_review as $chore): ?>
                    <div class="mission-card rounded-2xl p-5 border-brand-500/20 opacity-75">
                        <div class="flex items-start justify-between">
                            <div><h4 class="font-bold text-base"><?= e($chore['title']) ?></h4><p class="text-xs text-brand-400 mt-1">📤 Submitted — waiting for parent review</p></div>
                            <div class="flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-500/10 flex-shrink-0">
                                <span class="text-amber-400 text-xs">⭐</span><span class="text-amber-400 font-bold text-sm"><?= $chore['points'] ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Active Missions -->
        <div class="mb-8">
            <h3 class="text-xl font-bold mb-4 flex items-center gap-2"><span class="text-2xl">🎯</span> Active Missions <?php if (count($assigned) > 0): ?><span class="ml-2 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/15 text-amber-400"><?= count($assigned) ?></span><?php endif; ?></h3>
            <?php if (empty($assigned)): ?>
                <div class="glass rounded-2xl p-12 text-center"><div class="text-6xl mb-4">🎉</div><h4 class="text-xl font-bold mb-2">All Missions Complete!</h4><p class="text-gray-400">You've finished everything. Time to relax, hero! 😎</p></div>
            <?php else: ?>
                <div class="grid sm:grid-cols-2 gap-4">
                    <?php foreach ($assigned as $chore): ?>
                        <div class="mission-card rounded-2xl p-5">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-bold text-base"><?= e($chore['title']) ?></h4>
                                    <?php if ($chore['description']): ?><p class="text-gray-400 text-sm mt-1"><?= e($chore['description']) ?></p><?php endif; ?>
                                </div>
                                <div class="flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-500/10 ml-3 flex-shrink-0">
                                    <span class="text-amber-400 text-xs">⭐</span><span class="text-amber-400 font-bold text-sm"><?= $chore['points'] ?></span>
                                </div>
                            </div>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="submit_chore">
                                <input type="hidden" name="chore_id" value="<?= $chore['id'] ?>">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-xs text-gray-500"><?php if ($chore['due_date']): ?>📅 Due <?= formatShortDate($chore['due_date']) ?><?php else: ?>📅 No deadline<?php endif; ?></div>
                                    <div class="flex items-center gap-2">
                                        <label class="cursor-pointer px-3 py-2 rounded-xl text-xs text-gray-400 hover:text-gray-300 hover:bg-white/5 transition-all border border-white/5">
                                            📷
                                            <input type="file" name="proof_photo" accept="image/*" class="hidden" onchange="this.parentElement.querySelector('span')?.remove(); this.parentElement.insertAdjacentHTML('beforeend', '<span class=\'text-mint-400 ml-1\'>✓</span>')">
                                        </label>
                                        <button type="submit" onclick="celebrateComplete(event)" class="btn-success px-4 py-2 rounded-xl text-white font-bold text-xs uppercase tracking-wider">✓ Done!</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Reward Shop -->
        <?php if (!empty($rewards_list)): ?>
        <div class="mb-8">
            <h3 class="text-xl font-bold mb-4 flex items-center gap-2"><span class="text-2xl">🏪</span> Reward Shop</h3>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($rewards_list as $reward): $canAfford = $kid_points >= $reward['points_cost']; ?>
                    <div class="reward-card rounded-2xl p-5 <?= $canAfford ? '' : 'opacity-50' ?>">
                        <div class="text-4xl mb-3"><?= $reward['emoji'] ?></div>
                        <h4 class="font-bold text-sm mb-1"><?= e($reward['title']) ?></h4>
                        <?php if ($reward['description']): ?><p class="text-gray-500 text-xs mb-3"><?= e($reward['description']) ?></p><?php endif; ?>
                        <div class="flex items-center justify-between mt-3">
                            <span class="text-amber-400 font-bold text-sm">⭐ <?= $reward['points_cost'] ?> pts</span>
                            <div class="flex gap-2">
                                <?php if ($kid_age_group === 'young' && $kid_target != $reward['id']): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="set_target_reward">
                                        <input type="hidden" name="reward_id" value="<?= $reward['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-mint-400 border border-mint-500/20 hover:bg-mint-500/10 transition-colors">Set Goal</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canAfford): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Redeem this reward for <?= $reward['points_cost'] ?> points?')">
                                        <input type="hidden" name="action" value="redeem_reward">
                                        <input type="hidden" name="reward_id" value="<?= $reward['id'] ?>">
                                        <button type="submit" class="btn-primary px-4 py-1.5 rounded-lg text-white font-bold text-xs uppercase tracking-wider">Redeem</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-[10px] text-gray-500 font-semibold self-center">Need <?= $reward['points_cost'] - $kid_points ?> more</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Wallet & Vault -->
        <div class="mb-8 wallet-section">
            <h3 class="text-xl font-bold mb-4 flex items-center gap-2"><span class="text-2xl">🏦</span> <?= $kid_age_group === 'teen' ? 'Savings Vault' : 'My Wallet' ?></h3>
            <div class="grid lg:grid-cols-3 gap-6">
                <div class="lg:col-span-1">
                    <div class="vault-card rounded-2xl p-6">
                        <div class="text-center mb-5">
                            <div class="text-4xl mb-2">💰</div>
                            <div class="text-3xl font-black text-amber-400"><?= formatMoney($kid_savings) ?></div>
                            <div class="text-xs text-gray-400 uppercase tracking-wider mt-1">Available Balance</div>
                        </div>
                        <?php if ($kid_age_group === 'teen'): ?>
                        <?php if ($kid_savings > 0): ?>
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="action" value="savings_deposit">
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Amount to Lock</label>
                                <input type="number" name="deposit_amount" step="0.01" min="0.01" max="<?= $kid_savings ?>" placeholder="e.g. 5.00" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Lock Period</label>
                                <select name="lock_months" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none appearance-none cursor-pointer">
                                    <option value="3">3 Months</option><option value="6">6 Months</option><option value="9">9 Months</option>
                                </select>
                            </div>
                            <button type="submit" onclick="return confirm('Lock this amount? You won\'t be able to withdraw until the period ends.')" class="btn-amber w-full py-2.5 rounded-xl text-gray-900 font-bold text-sm">🔒 Lock Savings</button>
                        </form>
                        <?php else: ?>
                            <p class="text-center text-gray-500 text-sm">Complete chores to earn money and start saving!</p>
                        <?php endif; ?>
                        <?php else: ?>
                            <p class="text-center text-gray-500 text-sm">This is your pocket money! Use it wisely. 😎</p>
                        <?php endif; ?>
                    </div>

                    <!-- Record Spending -->
                    <div class="vault-card rounded-2xl p-6 mt-4">
                        <h4 class="font-bold text-sm mb-3 flex items-center gap-2"><span>💸</span> Record Spending</h4>
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="action" value="record_spending">
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Amount Spent</label>
                                <input type="number" name="spend_amount" step="0.01" min="0.01" max="<?= $kid_savings ?>" required placeholder="0.00" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">What for?</label>
                                <input type="text" name="spend_description" required placeholder="e.g. Candy, Toy, Game" class="input-field w-full px-4 py-2.5 rounded-xl text-white text-sm outline-none">
                            </div>
                            <button type="submit" onclick="return confirm('Record this expense? This will deduct from your balance.')" class="btn-danger w-full py-2.5 rounded-xl text-white font-bold text-sm">Record Expense</button>
                        </form>
                    </div>
                </div>
                <div class="lg:col-span-2 space-y-4">
                    <?php if ($kid_age_group === 'teen'): ?>
                    <?php if (empty($savings_goals)): ?>
                        <div class="glass rounded-2xl p-8 text-center"><div class="text-5xl mb-3">🏦</div><p class="text-gray-400 text-sm">No savings goals yet. Lock some money away and watch it grow!</p></div>
                    <?php else: ?>
                        <?php foreach ($savings_goals as $goal):
                            $isUnlocked = $goal['status'] === 'unlocked' || strtotime($goal['unlocks_at']) <= time();
                            $timeLeft = timeRemaining($goal['unlocks_at']);
                            $totalDays = $goal['lock_months'] * 30;
                            $daysElapsed = max(0, $totalDays - ((strtotime($goal['unlocks_at']) - time()) / 86400));
                            $vaultProgress = min(100, ($daysElapsed / max(1, $totalDays)) * 100);
                        ?>
                            <div class="vault-card rounded-2xl p-5 <?= $isUnlocked ? 'border-mint-500/30' : '' ?>">
                                <div class="flex items-center justify-between mb-3">
                                    <div>
                                        <div class="font-bold text-lg text-amber-400"><?= formatMoney($goal['amount']) ?></div>
                                        <div class="text-xs text-gray-500"><?= $goal['lock_months'] ?> month lock • Started <?= formatDate($goal['locked_at'], 'M j, Y') ?></div>
                                    </div>
                                    <div class="text-right">
                                        <?php if ($isUnlocked): ?>
                                            <form method="POST" class="inline"><input type="hidden" name="action" value="savings_withdraw"><input type="hidden" name="goal_id" value="<?= $goal['id'] ?>"><button type="submit" class="btn-success px-4 py-2 rounded-xl text-white font-bold text-xs uppercase tracking-wider">💰 Withdraw</button></form>
                                        <?php else: ?>
                                            <div class="text-sm font-semibold text-gray-400">🔒 <?= $timeLeft ?></div>
                                            <div class="text-xs text-gray-600">Unlocks <?= formatDate($goal['unlocks_at'], 'M j, Y') ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="w-full h-2 rounded-full bg-white/5 overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-1000 <?= $isUnlocked ? 'bg-mint-400' : '' ?>" style="width: <?= $vaultProgress ?>%; <?= $isUnlocked ? '' : 'background: linear-gradient(90deg, #fab005, #f59f00);' ?>"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($transactions)): ?>
                    <div class="glass rounded-2xl p-5">
                        <h4 class="font-bold text-sm mb-3 flex items-center gap-2"><span>📜</span> Recent Transactions</h4>
                        <div class="space-y-2">
                            <?php foreach ($transactions as $txn):
                                $typeIcons = ['chore_earning' => '✅', 'reward_redemption' => '🎁', 'savings_deposit' => '🔒', 'savings_withdrawal' => '💰', 'manual_adjustment' => '✍️'];
                                $isPositive = $txn['amount'] > 0;
                            ?>
                                <div class="flex items-center justify-between py-2 border-b border-white/5 last:border-0">
                                    <div class="flex items-center gap-2"><span class="text-sm"><?= $typeIcons[$txn['type']] ?? '📝' ?></span><span class="text-xs text-gray-400"><?= e($txn['description']) ?></span></div>
                                    <span class="text-xs font-bold <?= $isPositive ? 'text-mint-400' : 'text-coral-400' ?>"><?= $isPositive ? '+' : '' ?><?= formatMoney($txn['amount']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Completed Missions -->
        <?php if (!empty($completed)): ?>
        <div>
            <h3 class="text-xl font-bold mb-4 flex items-center gap-2"><span class="text-2xl">🏆</span> Completed Missions <span class="ml-2 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-400"><?= count($completed) ?></span></h3>
            <div class="space-y-2">
                <?php foreach ($completed as $chore): ?>
                    <div class="completed-card rounded-xl p-4 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">✅</span>
                            <div>
                                <span class="text-sm text-gray-400 line-through"><?= e($chore['title']) ?></span>
                                <?php if ($chore['completed_at']): ?><span class="text-xs text-gray-600 ml-2"><?= formatDate($chore['completed_at'], 'M j, g:ia') ?></span><?php endif; ?>
                            </div>
                        </div>
                        <span class="text-xs text-amber-500/60">+<?= $chore['points'] ?> pts</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- Mobile Bottom Navigation (Kid) -->
    <nav class="mobile-nav">
        <!-- Chores Tab -->
        <a href="#" class="mobile-nav-item active" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
            <span class="icon">📋</span>
            <span>Missions</span>
        </a>
        <!-- Rewards Tab -->
        <a href="#" class="mobile-nav-item" onclick="document.querySelector('.reward-card')?.scrollIntoView({behavior: 'smooth', block: 'center'})">
            <span class="icon">🎁</span>
            <span>Rewards</span>
        </a>
        <!-- Wallet Tab -->
        <a href="#" class="mobile-nav-item" onclick="document.querySelector('.wallet-section')?.scrollIntoView({behavior: 'smooth', block: 'center'})">
            <span class="icon">🏦</span>
            <span>Wallet</span>
        </a>
    </nav>

    <script>
        function celebrateComplete(e) {
            const emojis = ['🎉', '⭐', '🌟', '✨', '🎊', '🏆', '💪', '🚀'];
            const btn = e.target;
            const rect = btn.getBoundingClientRect();
            for (let i = 0; i < 8; i++) {
                const emoji = document.createElement('div');
                emoji.className = 'confetti-emoji';
                emoji.textContent = emojis[Math.floor(Math.random() * emojis.length)];
                emoji.style.left = (rect.left + Math.random() * 60 - 30) + 'px';
                emoji.style.top = (rect.top + Math.random() * 20) + 'px';
                emoji.style.animationDelay = (Math.random() * 0.3) + 's';
                document.body.appendChild(emoji);
                setTimeout(() => emoji.remove(), 2000);
            }
        }
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function() {
                if (this.files.length > 0) {
                    const label = this.closest('label');
                    if (label) { const span = label.querySelector('span'); if (span) span.textContent = '✓ ' + this.files[0].name.substring(0, 15) + '...'; }
                }
            });
        });
    </script>
</body>
</html>
