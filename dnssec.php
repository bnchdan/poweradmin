<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

/**
 * Script that handles editing of zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\DnsRecord;
use Poweradmin\Dnssec;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$pdnssec_use = $app->config('pdnssec_use');

if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
    error(ERR_INV_INPUT);
    include_once('inc/footer.inc.php');
    exit;
}
$zone_id = htmlspecialchars($_GET['id']);

if (do_hook('verify_permission', 'zone_meta_edit_others')) {
    $perm_meta_edit = "all";
} elseif (do_hook('verify_permission', 'zone_meta_edit_own')) {
    $perm_meta_edit = "own";
} else {
    $perm_meta_edit = "none";
}

if (do_hook('verify_permission', 'zone_content_view_others')) {
    $perm_view = "all";
} elseif (do_hook('verify_permission', 'zone_content_view_own')) {
    $perm_view = "own";
} else {
    $perm_view = "none";
}

$user_is_zone_owner = do_hook('verify_user_is_owner_zoneid' , $zone_id );
if ($perm_meta_edit == "all" || ( $perm_meta_edit == "own" && $user_is_zone_owner == "1")) {
    $meta_edit = "1";
} else {
    $meta_edit = "0";
}

(do_hook('verify_permission' , 'user_view_others' )) ? $perm_view_others = "1" : $perm_view_others = "0";

if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
    error(ERR_PERM_VIEW_ZONE);
    include_once("inc/footer.inc.php");
    exit();
}

if (DnsRecord::zone_id_exists($zone_id) == "0") {
    error(ERR_ZONE_NOT_EXIST);
    include_once("inc/footer.inc.php");
    exit();
}

$domain_type = DnsRecord::get_domain_type($zone_id);
$domain_name = DnsRecord::get_domain_name_by_id($zone_id);
$record_count = DnsRecord::count_zone_records($zone_id);
$zone_templates = ZoneTemplate::get_list_zone_templ($_SESSION['userid']);
$zone_template_id = DnsRecord::get_zone_template($zone_id);

echo "   <h5 class=\"mb-3\">" . _('DNSSEC keys for zone') . " \"" . DnsRecord::get_domain_name_by_id($zone_id) . "\"</h5>\n";

echo "     <table class=\"table table-striped table-hover table-sm\">\n";
echo "      <tr>\n";
echo "       <th>" . _('ID') . "</th>\n";
echo "       <th>" . _('Type') . "</th>\n";
echo "       <th>" . _('Tag') . "</th>\n";
echo "       <th>" . _('Algorithm') . "</th>\n";
echo "       <th>" . _('Bits') . "</th>\n";
echo "       <th>" . _('Active') . "</th>\n";
echo "       <th>&nbsp;</th>\n";
echo "      </tr>\n";

$keys = Dnssec::dnssec_get_keys($domain_name);

foreach ($keys as $item) {
    $button_title = $item[5] ? _('Deactivate zone key') : _('Activate zone key');
    echo "<tr>\n";
    echo "<td>".$item[0]."</td>\n";
    echo "<td>".$item[1]."</td>\n";
    echo "<td>".$item[2]."</td>\n";
    echo "<td>".Dnssec::dnssec_algorithm_to_name($item[3])."</td>\n";
    echo "<td>".$item[4]."</td>\n";
    echo "<td>".($item[5] ? _('Yes') : _('No'))."</td>\n";
    echo "<td>\n";
    echo "<a class=\"btn btn-outline-primary btn-sm\" href=\"dnssec_edit_key.php?id=" . $zone_id . "&key_id=" . $item[0] . "\"><i class=\"bi bi-pencil-square\"></i> " . $button_title . "</a>\n";
    echo "<a class=\"btn btn-outline-danger btn-sm\" href=\"dnssec_delete_key.php?id=" . $zone_id . "&key_id=" . $item[0] . "\"><i class=\"bi bi-trash\"></i> " . _('Delete zone key') . "</a>\n";
    echo "</td>";
    echo "</tr>\n";
}

echo "     </table>\n";
echo "      <input class=\"btn btn-primary btn-sm\" type=\"button\" onClick=\"location.href = 'dnssec_add_key.php?id=".$zone_id."';\" value=\"" . _('Add new key') . "\">\n";
echo "      <input class=\"btn btn-secondary btn-sm\" type=\"button\" onClick=\"location.href = 'dnssec_ds_dnskey.php?id=".$zone_id."';\" value=\"" . _('Show DS and DNSKEY') . "\">\n";

include_once("inc/footer.inc.php");
