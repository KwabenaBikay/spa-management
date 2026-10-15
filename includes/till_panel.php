<?php

function render_till_panel(array $expected, array $history, string $date): void
{
    $cashGap = null;
    $momoGap = null;
    foreach ($history as $row) {
        if (($row['close_date'] ?? '') === $date) {
            $cashGap = (float)$row['cash_counted'] - (float)$row['cash_expected'];
            $momoGap = (float)$row['momo_counted'] - (float)$row['momo_expected'];
            break;
        }
    }
    ?>
    <div class="flat-card">
        <div class="flat-card-header">
            <h3>Count the drawer</h3>
            <form method="get" class="date-form">
                <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>">
                <button type="submit" class="btn">Show day</button>
            </form>
        </div>
        <p class="lede">The system total is what was registered that day, after voids and refunds. Enter what is actually in the drawer and on Mobile Money.</p>
        <div class="expect-grid">
            <div><span>Cash in system</span><strong>GHS <?= number_format((float)$expected['cash'], 2) ?></strong></div>
            <div><span>Mobile Money in system</span><strong>GHS <?= number_format((float)$expected['momo'], 2) ?></strong></div>
            <div><span>Visits counted</span><strong><?= (int)$expected['visits'] ?></strong></div>
        </div>
        <?php if ($cashGap !== null): ?>
        <p class="variance <?= (abs($cashGap) < 0.01 && abs($momoGap) < 0.01) ? 'ok' : 'off' ?>">
            Last count for this day:
            cash <?= $cashGap >= 0 ? '+' : '' ?><?= number_format($cashGap, 2) ?>,
            Mobile Money <?= $momoGap >= 0 ? '+' : '' ?><?= number_format($momoGap, 2) ?>.
        </p>
        <?php endif; ?>
        <form method="post" class="count-form">
            <?= csrf_field() ?>
            <input type="hidden" name="close_till" value="1">
            <input type="hidden" name="close_date" value="<?= htmlspecialchars($date) ?>">
            <label>Cash counted<input type="number" name="cash_counted" min="0" step="0.01" required></label>
            <label>Mobile Money counted<input type="number" name="momo_counted" min="0" step="0.01" required></label>
            <label>Note<input type="text" name="note" maxlength="255" placeholder="Optional"></label>
            <button type="submit" class="btn">Save close</button>
        </form>
    </div>
    <div class="flat-card">
        <div class="flat-card-header"><h3>Recent closes</h3></div>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Cash</th>
                        <th>Mobile Money</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $row):
                    $cashDiff = (float)$row['cash_counted'] - (float)$row['cash_expected'];
                    $momoDiff = (float)$row['momo_counted'] - (float)$row['momo_expected'];
                ?>
                    <tr>
                        <td><?= htmlspecialchars(date('D, M j', strtotime($row['close_date']))) ?></td>
                        <td>GHS <?= number_format((float)$row['cash_counted'], 2) ?> <span class="diff"><?= $cashDiff >= 0 ? '+' : '' ?><?= number_format($cashDiff, 2) ?></span></td>
                        <td>GHS <?= number_format((float)$row['momo_counted'], 2) ?> <span class="diff"><?= $momoDiff >= 0 ? '+' : '' ?><?= number_format($momoDiff, 2) ?></span></td>
                        <td><?= htmlspecialchars($row['closed_by'] ?? '') ?><?php if (!empty($row['note'])): ?><span class="diff"><?= htmlspecialchars($row['note']) ?></span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$history): ?>
                    <tr><td colspan="4" style="text-align:center; color:#64748b; padding:28px;">No day has been closed yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
