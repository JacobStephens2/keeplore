<?php
require_once(PRIVATE_PATH . '/classes/ProposalOutcomes.php');
$proposalHistory = (new ProposalOutcomes($db, (int) $_SESSION['user_id']))->history((int) $artifact['id']);
?>
<section id="proposal-history">
    <h2>Proposal history</h2>
    <p><a href="<?php echo url_for('/proposals/index.php'); ?>">Compare proposal outcomes across items</a></p>
    <?php if (!$proposalHistory) { ?>
        <p>No proposal outcomes recorded for this item.</p>
    <?php } else { ?>
        <div class="proposal-table-wrap">
            <table>
                <thead><tr><th scope="col">Date</th><th scope="col">Outcome</th><th scope="col">Chosen instead</th><th scope="col">Participants</th><th scope="col">Note</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($proposalHistory as $proposal) { ?>
                        <tr>
                            <td><?php echo h($proposal['proposal_date']); ?></td>
                            <td><?php echo h(ProposalOutcomes::OUTCOMES[$proposal['outcome']]); ?></td>
                            <td><?php echo h($proposal['chosen_item_name'] ?: '—'); ?></td>
                            <td><?php echo h(implode(', ', array_column($proposal['participants'], 'name')) ?: '—'); ?></td>
                            <td class="proposal-note"><?php echo h($proposal['note']); ?></td>
                            <td>
                                <a href="<?php echo url_for('/proposals/edit.php?id=' . $proposal['id']); ?>">Edit</a>
                                <a href="<?php echo url_for('/proposals/delete.php?id=' . $proposal['id']); ?>">Delete</a>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
