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
 */

/**
 * Script that handles requests to add new supermaster servers
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\DnsRecord;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$master_ip = $_POST["master_ip"] ?? "";
$ns_name = $_POST["ns_name"] ?? "";
$account = $_POST["account"] ?? "";

$supermasters_add = do_hook('verify_permission', 'supermaster_add');
$perm_view_others = do_hook('verify_permission', 'user_view_others');

$error = 0;
if (isset($_POST["submit"])) {
    if (DnsRecord::add_supermaster($master_ip, $ns_name, $account)) {
        success(SUC_SM_ADD);
    } else {
        $error = "1";
    }
}

echo "     <h5 class=\"mb-3\">" . _('Add supermaster') . "</h5>\n";

if (!$supermasters_add) {
    echo "     <p>" . _("You do not have the permission to add a new supermaster.") . "</p>\n";
    include_once('inc/footer.inc.php');
    exit;
}

echo "     <form method=\"post\" action=\"add_supermaster.php\">\n";
echo "      <table>\n";
echo "       <tr>\n";
echo "        <td>" . _('IP address of supermaster') . "</td>\n";
echo "        <td>\n";
if ($error) {
    echo "         <input type=\"text\" name=\"master_ip\" value=\"" . $master_ip . "\">\n";
} else {
    echo "         <input class=\"form-control form-control-sm\" type=\"text\" name=\"master_ip\" value=\"\">\n";
}
echo "        </td>\n";
echo "       </tr>\n";
echo "       <tr>\n";
echo "        <td>" . _('Hostname in NS record') . "</td>\n";
echo "        <td>\n";
if ($error) {
    echo "         <input type=\"text\" name=\"ns_name\" value=\"" . $ns_name . "\">\n";
} else {
    echo "         <input class=\"form-control form-control-sm\" type=\"text\" name=\"ns_name\" value=\"\">\n";
}
echo "        </td>\n";
echo "       </tr>\n";
echo "       <tr>\n";
echo "        <td>" . _('Account') . "</td>\n";
echo "        <td>\n";

echo "         <select class=\"form-select form-select-sm\" name=\"account\">\n";
/*
  Display list of users to assign slave zone to if the
  editing user has the permissions to, otherwise just
  display the adding users name
 */
$users = do_hook('show_users');
foreach ($users as $user) {
    if ($user['id'] === $_SESSION['userid']) {
        echo "          <option value=\"" . $user['username'] . "\" selected>" . $user['fullname'] . "</option>\n";
    } elseif ($perm_view_others) {
        echo "          <option value=\"" . $user['username'] . "\">" . $user['fullname'] . "</option>\n";
    }
}
echo "         </select>\n";

echo "        </td>\n";
echo "       </tr>\n";
echo "       <tr>\n";
echo "        <td>&nbsp;</td>\n";
echo "        <td>\n";
echo "         <input class=\"btn btn-primary btn-sm\" type=\"submit\" name=\"submit\" value=\"" . _('Add supermaster') . "\">\n";
echo "        </td>\n";
echo "       </tr>\n";
echo "      </table>\n";
echo "     </form>\n";

include_once('inc/footer.inc.php');
