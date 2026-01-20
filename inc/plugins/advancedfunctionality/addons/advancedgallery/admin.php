<?php
/**
 * AF Addon: AdvancedGallery — Admin controller
 */
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('IN_ADMINCP')) { define('IN_ADMINCP', 1); }

class AF_Admin_Advancedgallery
{
    public static function dispatch(string $action = ''): string
    {
        $html = self::render($action);
        echo $html;
        return $html;
    }

    public static function render(string $action = ''): string
    {
        global $db, $mybb;

        $action = $action !== '' ? $action : $mybb->get_input('action');

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            if ($action === 'approve') {
                $id = $mybb->get_input('id', MyBB::INPUT_INT);
                if ($id > 0) {
                    $db->update_query('af_gallery_media', [
                        'status' => 'approved',
                        'updated_at' => TIME_NOW,
                    ], "id='{$id}'");
                }
            }

            if ($action === 'reject') {
                $id = $mybb->get_input('id', MyBB::INPUT_INT);
                if ($id > 0) {
                    $db->update_query('af_gallery_media', [
                        'status' => 'rejected',
                        'updated_at' => TIME_NOW,
                    ], "id='{$id}'");
                }
            }

            if ($action === 'bulk_approve' && !empty($_POST['media_ids']) && is_array($_POST['media_ids'])) {
                $ids = array_map('intval', $_POST['media_ids']);
                $ids = array_filter($ids);
                if ($ids) {
                    $db->update_query('af_gallery_media', [
                        'status' => 'approved',
                        'updated_at' => TIME_NOW,
                    ], 'id IN (' . implode(',', $ids) . ')');
                }
            }
        }

        $total = (int)$db->fetch_field($db->simple_select('af_gallery_media', 'COUNT(*) AS cnt'), 'cnt');
        $approved = (int)$db->fetch_field($db->simple_select('af_gallery_media', 'COUNT(*) AS cnt', "status='approved'"), 'cnt');
        $pending = (int)$db->fetch_field($db->simple_select('af_gallery_media', 'COUNT(*) AS cnt', "status='pending'"), 'cnt');

        $gid = 0;
        $q = $db->simple_select('settinggroups', 'gid', "name='af_advancedgallery'", ['limit' => 1]);
        $row = $db->fetch_array($q);
        if (!empty($row['gid'])) {
            $gid = (int)$row['gid'];
        }

        $settingsUrl = 'index.php?module=config-settings';
        if ($gid > 0) {
            $settingsUrl .= '&action=change&gid='.$gid;
        }

        $pendingRows = '';
        $pendingQuery = $db->simple_select('af_gallery_media', '*', "status='pending'", [
            'order_by' => 'created_at',
            'order_dir' => 'ASC',
            'limit' => 50,
        ]);

        while ($media = $db->fetch_array($pendingQuery)) {
            $title = htmlspecialchars_uni($media['title']);
            $user = get_user((int)$media['uid_owner']);
            $authorName = htmlspecialchars_uni($user['username'] ?? '');
            $author = $authorName !== '' ? build_profile_link($authorName, (int)$media['uid_owner']) : '';

            $pendingRows .= '<tr>';
            $pendingRows .= '<td><input type="checkbox" name="media_ids[]" value="'.(int)$media['id'].'" /></td>';
            $pendingRows .= '<td>'.(int)$media['id'].'</td>';
            $pendingRows .= '<td>'.$title.'</td>';
            $pendingRows .= '<td>'.$author.'</td>';
            $pendingRows .= '<td>';
            $pendingRows .= '<form action="index.php?module=advancedfunctionality&af_view=advancedgallery&action=approve&id='.(int)$media['id'].'" method="post" style="display:inline;">'
                .'<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'" />'
                .'<button type="submit" class="button">Approve</button>'
                .'</form>';
            $pendingRows .= '<form action="index.php?module=advancedfunctionality&af_view=advancedgallery&action=reject&id='.(int)$media['id'].'" method="post" style="display:inline;margin-left:6px;">'
                .'<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'" />'
                .'<button type="submit" class="button">Reject</button>'
                .'</form>';
            $pendingRows .= '</td>';
            $pendingRows .= '</tr>';
        }

        if ($pendingRows === '') {
            $pendingRows = '<tr><td colspan="5">No pending media.</td></tr>';
        }

        $html = '';
        $html .= '<div class="af-box">';
        $html .= '<h2 style="margin:0 0 8px 0;">AdvancedGallery</h2>';
        $html .= '<p style="margin:0 0 12px 0;">Overview of gallery content and pending moderation queue.</p>';

        $html .= '<table class="general" cellspacing="0" cellpadding="5" style="width:100%;max-width:760px;">';
        $html .= '<tr><td style="width:220px;"><strong>Total</strong></td><td>'.$total.'</td></tr>';
        $html .= '<tr><td><strong>Approved</strong></td><td>'.$approved.'</td></tr>';
        $html .= '<tr><td><strong>Pending</strong></td><td>'.$pending.'</td></tr>';
        $html .= '</table>';

        $html .= '<div style="margin-top:12px;">';
        $html .= '<a class="button button-primary" href="'.htmlspecialchars($settingsUrl).'">Open settings</a>';
        $html .= '</div>';

        $html .= '<h3 style="margin:24px 0 8px 0;">Pending list</h3>';
        $html .= '<form action="index.php?module=advancedfunctionality&af_view=advancedgallery&action=bulk_approve" method="post">';
        $html .= '<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'" />';
        $html .= '<table class="general" cellspacing="0" cellpadding="5" style="width:100%;">';
        $html .= '<tr><th style="width:40px;"></th><th style="width:60px;">ID</th><th>Title</th><th>Author</th><th style="width:180px;">Actions</th></tr>';
        $html .= $pendingRows;
        $html .= '</table>';
        $html .= '<div style="margin-top:8px;">';
        $html .= '<button type="submit" class="button">Bulk approve</button>';
        $html .= '</div>';
        $html .= '</form>';

        $html .= '</div>';

        return $html;
    }
}
