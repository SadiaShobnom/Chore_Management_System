<?php
/**
 * Reusable Helper Functions
 * ChoreQuest
 */

/**
 * Calculate age from date of birth.
 */
function calcAge(string $dob): int {
    $birth = new DateTime($dob);
    $today = new DateTime('today');
    return $birth->diff($today)->y;
}

/**
 * Get age group: 'young' (5-10) or 'teen' (11-18).
 */
function getAgeGroup(string $dob): string {
    $age = calcAge($dob);
    return $age <= 10 ? 'young' : 'teen';
}

/**
 * Format a date string for display.
 */
function formatDate(string $date, string $format = 'M j, Y'): string {
    return date($format, strtotime($date));
}

/**
 * Format a short date (e.g., "Mar 5").
 */
function formatShortDate(string $date): string {
    return date('M j', strtotime($date));
}

/**
 * Format currency for display.
 */
function formatMoney(float $amount): string {
    return '$' . number_format($amount, 2);
}

/**
 * Handle file upload for chore proof photos.
 * Returns the relative file path on success, or false on failure.
 */
function uploadProofPhoto(array $file): string|false {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/jpg'];
    $maxSize = 10 * 1024 * 1024; // 10MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        error_log("Upload Error: " . $file['error']);
        return false;
    }

    if (!in_array($file['type'], $allowedTypes, true)) {
        error_log("Invalid File Type: " . $file['type']);
        return false;
    }

    if ($file['size'] > $maxSize) {
        error_log("File too large: " . $file['size']);
        return false;
    }

    // Verify it's actually an image
    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return false;
    }

    // Generate unique filename
    $ext = match ($file['type']) {
        'image/jpeg' => '.jpg',
        'image/png'  => '.png',
        'image/webp' => '.webp',
        default      => '.jpg',
    };

    $filename = uniqid('proof_', true) . $ext;
    $destination = __DIR__ . '/../uploads/proofs/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'uploads/proofs/' . $filename;
    }

    return false;
}

/**
 * Sanitize a string for safe HTML output.
 */
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Calculate the level and progress info for a kid based on points.
 * Returns an associative array with level info.
 */
function getLevelInfo(int $points): array {
    $levels = [
        ['name' => 'Rookie',       'min' => 0],
        ['name' => 'Explorer',     'min' => 25],
        ['name' => 'Champion',     'min' => 75],
        ['name' => 'Legend',       'min' => 150],
        ['name' => 'Master',       'min' => 300],
        ['name' => 'Grand Master', 'min' => 500],
    ];

    $level = 1;
    $levelName = $levels[0]['name'];
    $currentThreshold = 0;
    $nextThreshold = $levels[1]['min'];

    for ($i = count($levels) - 1; $i >= 0; $i--) {
        if ($points >= $levels[$i]['min']) {
            $level = $i + 1;
            $levelName = $levels[$i]['name'];
            $currentThreshold = $levels[$i]['min'];
            $nextThreshold = isset($levels[$i + 1]) ? $levels[$i + 1]['min'] : 999999;
            break;
        }
    }

    $range = max(1, $nextThreshold - $currentThreshold);
    $progress = min(100, (($points - $currentThreshold) / $range) * 100);

    return [
        'level'            => $level,
        'name'             => $levelName,
        'points'           => $points,
        'currentThreshold' => $currentThreshold,
        'nextThreshold'    => $nextThreshold,
        'progress'         => round($progress, 1),
    ];
}

/**
 * Get a human-readable time remaining string.
 */
function timeRemaining(string $futureDate): string {
    $now = new DateTime();
    $target = new DateTime($futureDate);

    if ($now >= $target) {
        return 'Unlocked!';
    }

    $diff = $now->diff($target);

    if ($diff->m > 0) {
        return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ', ' . $diff->d . ' day' . ($diff->d > 1 ? 's' : '');
    }
    if ($diff->d > 0) {
        return $diff->d . ' day' . ($diff->d > 1 ? 's' : '');
    }
    return 'Less than a day';
}

/**
 * Update the streak for a kid after a chore is approved.
 * Returns an array with streak info and any bonus earned.
 */
function updateStreak(PDO $pdo, int $kid_id): array {
    $stmt = $pdo->prepare("SELECT current_streak, longest_streak, last_chore_date FROM kids WHERE id = ?");
    $stmt->execute([$kid_id]);
    $kid = $stmt->fetch();

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $lastDate = $kid['last_chore_date'];
    $streak = $kid['current_streak'];
    $longest = $kid['longest_streak'];
    $bonus = 0;
    $bonusMsg = '';

    if ($lastDate === $today) {
        // Already did a chore today — no streak change
        return ['streak' => $streak, 'longest' => $longest, 'bonus' => 0, 'msg' => ''];
    } elseif ($lastDate === $yesterday) {
        // Consecutive day — increment streak
        $streak++;
    } else {
        // Streak broken or first chore — start at 1
        $streak = 1;
    }

    // Update longest streak
    if ($streak > $longest) {
        $longest = $streak;
    }

    // Bonus points for milestones
    if ($streak === 3) {
        $bonus = 5;
        $bonusMsg = "🔥 3-day streak! +5 bonus points!";
    } elseif ($streak === 7) {
        $bonus = 15;
        $bonusMsg = "🔥🔥 7-day streak! +15 bonus points!";
    } elseif ($streak === 14) {
        $bonus = 30;
        $bonusMsg = "🔥🔥🔥 14-day streak! +30 bonus points!";
    } elseif ($streak === 30) {
        $bonus = 50;
        $bonusMsg = "⚡ 30-day streak! +50 bonus points!";
    }

    // Award bonus
    if ($bonus > 0) {
        $stmt = $pdo->prepare("UPDATE kids SET points = points + ? WHERE id = ?");
        $stmt->execute([$bonus, $kid_id]);
    }

    // Update streak data
    $stmt = $pdo->prepare("UPDATE kids SET current_streak = ?, longest_streak = ?, last_chore_date = ? WHERE id = ?");
    $stmt->execute([$streak, $longest, $today, $kid_id]);

    return ['streak' => $streak, 'longest' => $longest, 'bonus' => $bonus, 'msg' => $bonusMsg];
}
