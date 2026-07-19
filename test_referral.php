<?php
/**
 * test_referral.php - consumption/reset reward model + redemption for HL_Referral.
 * Uses a throwaway sqlite seeded via the bot Database schema.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/referral.php';

$P = 0; $F = 0;
function ok($cond, $msg) { global $P, $F; if ($cond) { $P++; echo "  PASS  $msg\n"; } else { $F++; echo "  FAIL  $msg\n"; } }

$path = sys_get_temp_dir() . '/hl_ref_test_' . getmypid() . '.sqlite';
@unlink($path);
$db = new Database($path);                 // creates users/settings/promotions/etc.
$ref = new HL_Referral($path);             // creates referral_* tables + seeds tiers

// Turn off the group-join gate + settle window so qualified inserts count directly.
$ref->setSetting('referral_require_group_join', '0');
$ref->setSetting('referral_qualify_days', '0');

// Raw handle to inject qualified referrals + a user row.
$raw = new SQLite3($path);
$raw->exec("INSERT INTO users (telegram_id, name, phone, email, registered_at) VALUES (100,'Inviter','','',datetime('now'))");
$rid = 1000;
function addQualified($raw, $inviter, $n) {
    global $rid;
    for ($i = 0; $i < $n; $i++) {
        $rid++;
        $raw->exec("INSERT INTO referrals (referrer_id, referred_id, referred_name, status, group_joined, registered_at, qualifies_at, qualified_at)
                    VALUES ($inviter, $rid, 'F$rid', 'qualified', 1, datetime('now'), datetime('now'), datetime('now'))");
    }
}

echo "\n[1] Default tiers carry fulfillment packages\n";
$tiers = $ref->tiers(true);
ok(count($tiers) === 3, 'three default tiers seeded');
ok(($tiers[0]['fulfill_package'] ?? '') === 'monthly', 'tier 20 -> monthly');
ok(($tiers[1]['fulfill_package'] ?? '') === 'botw', 'tier 50 -> botw');
ok(($tiers[2]['fulfill_package'] ?? '') === '', 'tier 100 (bundle) -> manual');

echo "\n[2] Reward earns automatically at the first tier, consuming its invites\n";
addQualified($raw, 100, 20);
ok($ref->availableInvites(100) === 20, '20 available before earning');
$new = $ref->checkMilestones(100);
ok(count($new) === 1, 'exactly one reward earned');
ok($ref->rewardCount(100) === 1, 'reward count = 1');
ok($ref->spentInvites(100) === 20, '20 invites spent');
ok($ref->availableInvites(100) === 0, 'progress reset to 0 after claim');
$nt = $ref->nextTier(100);
ok((int)$nt['invites_required'] === 50, 'next tier is now the 50 tier');

echo "\n[3] Second tier needs a FRESH 50 invites (not 30 more)\n";
addQualified($raw, 100, 30);                 // total 50 qualified, 20 spent -> 30 available
ok($ref->availableInvites(100) === 30, '30 available, below the 50 tier');
ok(count($ref->checkMilestones(100)) === 0, 'no reward yet at 30 available');
addQualified($raw, 100, 20);                 // total 70 qualified -> 50 available
$new = $ref->checkMilestones(100);
ok(count($new) === 1, 'second reward earned at 50 available');
ok($ref->spentInvites(100) === 70, 'now 70 invites spent (20+50)');
ok($ref->availableInvites(100) === 0, 'progress reset again');

echo "\n[4] Top tier repeats so inviting keeps paying\n";
addQualified($raw, 100, 100);                // total 170 -> 100 available
ok(count($ref->checkMilestones(100)) === 1, 'third reward (100 tier) earned');
addQualified($raw, 100, 100);                // total 270 -> 100 available again
$new = $ref->checkMilestones(100);
ok(count($new) === 1, 'top tier earned a second time (repeatable)');
ok($ref->rewardCount(100) === 4, 'four rewards total');

echo "\n[5] Total invites is lifetime; progress is available (resets)\n";
$p = $ref->progress(100);
ok($p['qualified'] === 270, 'Total invites (lifetime) = 270');
ok($p['available'] === 0, 'available toward next = 0 right after a claim');

echo "\n[6] Changing a tier threshold does NOT reopen already-claimed rewards\n";
$spentBefore = $ref->spentInvites(100);
$ref->updateTier((int)$tiers[0]['id'], 5, $tiers[0]['title'], $tiers[0]['body'], 1, 'monthly');
ok($ref->spentInvites(100) === $spentBefore, 'spent invites unchanged after lowering a threshold');
ok(count($ref->checkMilestones(100)) === 0, 'no phantom re-claim from the settings change');

echo "\n[7] Redemption: reward maps to a package, redeem is one-shot\n";
$rewards = $ref->earnedRewards(100);
ok(count($rewards) === 4, 'all four rewards are earned/redeemable');
$first = $rewards[0];
ok($ref->rewardPackage($first) === 'monthly', 'first reward grants the monthly package');
ok($ref->markRewardRedeemed($first['id'], 555) === true, 'redeem succeeds and links promo 555');
$after = $ref->reward($first['id']);
ok($after['status'] === 'redeemed' && (int)$after['promo_id'] === 555, 'status redeemed, promo_id stored');
ok($ref->markRewardRedeemed($first['id'], 777) === false, 'second redeem blocked (no duplicate promo)');

echo "\n[8] Group-join gate: unqualified until the friend joins\n";
$ref->setSetting('referral_require_group_join', '1');
$raw->exec("INSERT OR REPLACE INTO settings (key,value) VALUES ('sched_group_chat_id','-100999')");
$raw->exec("INSERT INTO users (telegram_id, name, phone, email, registered_at) VALUES (300,'Inviter3','','',datetime('now'))");
$ref2 = new HL_Referral($path);
ok($ref2->requiresGroupJoin() === true, 'group-join rule active when a group is set');
list($okk,) = $ref2->attribute(3001, $ref2->ensureCode(300), 'NewFriend', '', '');
ok($okk === true, 'referral recorded on registration');
ok($ref2->countQualified(300) === 0, 'not counted before joining the group');
$ref2->markGroupJoined(3001);
$ref2->refreshQualifications();
ok($ref2->countQualified(300) === 1, 'counted only after group join is confirmed');

echo "\n=====================================\n";
echo "PASS: $P   FAIL: $F\n";
@unlink($path);
exit($F ? 1 : 0);
