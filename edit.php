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
use Poweradmin\RecordLog;
use Poweradmin\RecordType;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;
use Poweradmin\ZoneType;

require_once 'inc/toolkit.inc.php';
require_once 'inc/pagination.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();
$pdnssec_use = $app->config('pdnssec_use');
$iface_add_reverse_record = $app->config('iface_add_reverse_record');
$iface_rowamount = $app->config('iface_rowamount');
$iface_zone_comments = $app->config('iface_zone_comments');

if (isset($_GET["start"])) {
    define('ROWSTART', (($_GET["start"] - 1) * $iface_rowamount));
} else {
    define('ROWSTART', 0);
}

if (isset($_GET["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["record_sort_by"])) {
    define('RECORD_SORT_BY', $_GET["record_sort_by"]);
    $_SESSION["record_sort_by"] = $_GET["record_sort_by"];
} elseif (isset($_POST["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["record_sort_by"])) {
    define('RECORD_SORT_BY', $_POST["record_sort_by"]);
    $_SESSION["record_sort_by"] = $_POST["record_sort_by"];
} elseif (isset($_SESSION["record_sort_by"])) {
    define('RECORD_SORT_BY', $_SESSION["record_sort_by"]);
} else {
    define('RECORD_SORT_BY', "name");
}

if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;
}
$zone_id = htmlspecialchars($_GET['id']);

if (isset($_POST['commit'])) {
    $error = false;
    $one_record_changed = false;

    if (isset($_POST['record'])) {
        foreach ($_POST['record'] as $record) {
            $old_record_info = DnsRecord::get_record_from_id($record['rid']);

            // Check if a record changed and save the state
            $log = new RecordLog();
            $log->log_prior($record['rid']);
            if (!$log->has_changed($record)) {
                continue;
            } else {
                $one_record_changed = true;
            }

            $edit_record = DnsRecord::edit_record($record);
            if (false === $edit_record) {
                $error = true;
            } else {
                // Log the state after saving and write it to logging table
                $log->log_after($record['rid']);
                $log->write();
            }
        }
    }

    DnsRecord::edit_zone_comment($_GET['id'], $_POST['comment']);

    if (false === $error) {
        DnsRecord::update_soa_serial($_GET['id']);

        if ($one_record_changed) {
            success(SUC_ZONE_UPD);
        } else {
            success(SUC_ZONE_NOCHANGE);
        }

        if ($pdnssec_use) {
            if (Dnssec::dnssec_rectify_zone($_GET['id'])) {
                success(SUC_EXEC_PDNSSEC_RECTIFY_ZONE);
            }
        }
    } else {
        error(ERR_ZONE_UPD);
    }
}

if (isset($_POST['save_as'])) {
    if (ZoneTemplate::zone_templ_name_exists($_POST['templ_name'])) {
        error(ERR_ZONE_TEMPL_EXIST);
    } elseif ($_POST['templ_name'] == '') {
        error(ERR_ZONE_TEMPL_IS_EMPTY);
    } else {
        success(SUC_ZONE_TEMPL_ADD);
        $records = DnsRecord::get_records_from_domain_id($zone_id);
        ZoneTemplate::add_zone_templ_save_as($_POST['templ_name'], $_POST['templ_descr'], $_SESSION['userid'], $records, DnsRecord::get_domain_name_by_id($zone_id));
    }
}

/*
  Check permissions
 */
if (do_hook('verify_permission', 'zone_content_view_others')) {
    $perm_view = "all";
} elseif (do_hook('verify_permission', 'zone_content_view_own')) {
    $perm_view = "own";
} else {
    $perm_view = "none";
}

if (do_hook('verify_permission', 'zone_content_edit_others')) {
    $perm_content_edit = "all";
} elseif (do_hook('verify_permission', 'zone_content_edit_own')) {
    $perm_content_edit = "own";
} elseif (do_hook('verify_permission', 'zone_content_edit_own_as_client')) {
    $perm_content_edit = "own_as_client";
} else {
    $perm_content_edit = "none";
}

if (do_hook('verify_permission', 'zone_meta_edit_others')) {
    $perm_meta_edit = "all";
} elseif (do_hook('verify_permission', 'zone_meta_edit_own')) {
    $perm_meta_edit = "own";
} else {
    $perm_meta_edit = "none";
}

do_hook('verify_permission', 'zone_master_add') ? $perm_zone_master_add = "1" : $perm_zone_master_add = "0";
do_hook('verify_permission', 'zone_slave_add') ? $perm_zone_slave_add = "1" : $perm_zone_slave_add = "0";

$user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);
if ($perm_meta_edit == "all" || ($perm_meta_edit == "own" && $user_is_zone_owner == "1")) {
    $meta_edit = "1";
} else {
    $meta_edit = "0";
}

(do_hook('verify_permission', 'user_view_others')) ? $perm_view_others = "1" : $perm_view_others = "0";

if (isset($_POST['slave_master_change']) && is_numeric($_POST["domain"])) {
    DnsRecord::change_zone_slave_master($_POST['domain'], $_POST['new_master']);
}
if (isset($_POST['type_change']) && in_array($_POST['newtype'], ZoneType::getTypes())) {
    DnsRecord::change_zone_type($_POST['newtype'], $zone_id);
}
if (isset($_POST["newowner"]) && is_numeric($_POST["domain"]) && is_numeric($_POST["newowner"])) {
    DnsRecord::add_owner_to_zone($_POST["domain"], $_POST["newowner"]);
}
if (isset($_POST["delete_owner"]) && is_numeric($_POST["delete_owner"])) {
    DnsRecord::delete_owner_from_zone($zone_id, $_POST["delete_owner"]);
}
if (isset($_POST["template_change"])) {
    if (!isset($_POST['zone_template']) || "none" == $_POST['zone_template']) {
        $new_zone_template = 0;
    } else {
        $new_zone_template = $_POST['zone_template'];
    }
    if ($_POST['current_zone_template'] != $new_zone_template) {
        DnsRecord::update_zone_records($zone_id, $new_zone_template);
    }
}

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

if (isset($_POST['sign_zone'])) {
    $zone_name = DnsRecord::get_domain_name_by_id($zone_id);
    DnsRecord::update_soa_serial($zone_id);
    Dnssec::dnssec_secure_zone($zone_name);
    Dnssec::dnssec_rectify_zone($zone_id);
}

if (isset($_POST['unsign_zone'])) {
    $zone_name = DnsRecord::get_domain_name_by_id($zone_id);
    Dnssec::dnssec_unsecure_zone($zone_name);
    DnsRecord::update_soa_serial($zone_id);
}

$domain_type = DnsRecord::get_domain_type($zone_id);
$record_count = DnsRecord::count_zone_records($zone_id);
$zone_templates = ZoneTemplate::get_list_zone_templ($_SESSION['userid']);
$zone_template_id = DnsRecord::get_zone_template($zone_id);

$zone_name_to_display = DnsRecord::get_domain_name_by_id($zone_id);
if (preg_match("/^xn--/", $zone_name_to_display)) {
    $idn_zone_name = idn_to_utf8($zone_name_to_display, IDNA_NONTRANSITIONAL_TO_ASCII);
    echo "   <h5 class=\"mb-3\">" . _('Edit zone') . " \"" . $idn_zone_name . "\" (\"" . $zone_name_to_display . "\")</h5>\n";
} else {
    echo "   <h5 class=\"mb-3\">" . _('Edit zone') . " \"" . $zone_name_to_display . "\"</h5>\n";
}

echo "   <div>\n";
echo show_pages($record_count, $iface_rowamount, $zone_id);
echo "   </div>\n";

$records = DnsRecord::get_records_from_domain_id($zone_id, ROWSTART, $iface_rowamount, RECORD_SORT_BY);
if ($records == "-1") {
    echo " <p>" . _("This zone does not have any records. Weird.") . "</p>\n";
} else {
    echo "   <form method=\"post\" action=\"\">\n";
    echo "   <table class=\"table table-striped table-hover table-sm\">\n";
    echo "    <tr>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=id\">" . _('Id') . "</a></th>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=name\">" . _('Name') . "</a></th>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=type\">" . _('Type') . "</a></th>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=content\">" . _('Content') . "</a></th>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=prio\">" . _('Priority') . "</a></th>\n";
    echo "     <th><a href=\"edit.php?id=" . $zone_id . "&amp;record_sort_by=ttl\">" . _('TTL') . "</a></th>\n";
    echo "     <th>&nbsp;</th>\n";
    echo "    </tr>\n";
    foreach ($records as $r) {
        if (!($r['type'] == "SOA" || ($r['type'] == "NS" && $perm_content_edit == "own_as_client"))) {
            echo "    <input type=\"hidden\" name=\"record[" . $r['id'] . "][rid]\" value=\"" . $r['id'] . "\">\n";
            echo "    <input type=\"hidden\" name=\"record[" . $r['id'] . "][zid]\" value=\"" . $zone_id . "\">\n";
        }
        echo "    <tr>\n";
        echo "     <td>{$r['id']}</td>\n";
        if ($r['type'] == "SOA" || ($r['type'] == "NS" && $perm_content_edit == "own_as_client")) {
            echo "     <td>" . $r['name'] . "</td>\n";
            echo "     <td>" . $r['type'] . "</td>\n";
            echo "     <td>" . $r['content'] . "</td>\n";
            echo "     <td>&nbsp;</td>\n";
            echo "     <td>" . $r['ttl'] . "</td>\n";
        } else {
            echo "      <td class=\"u\"><input name=\"record[" . $r['id'] . "][name]\" value=\"" . htmlspecialchars($r['name']) . "\"></td>\n";
            echo "      <td class=\"u\">\n";
            echo "       <select class=\"form-select form-select-sm\" name=\"record[" . $r['id'] . "][type]\">\n";
            $found_selected_type = false;
            foreach (RecordType::getTypes() as $type_available) {
                if ($type_available == $r['type']) {
                    $add = " SELECTED";
                    $found_selected_type = true;
                } else {
                    $add = "";
                }
                echo "         <option" . $add . " value=\"" . htmlspecialchars($type_available) . "\" >" . $type_available . "</option>\n";
            }
            if (!$found_selected_type)
                echo "         <option SELECTED value=\"" . htmlspecialchars($r['type']) . "\"><i>" . $r['type'] . "</i></option>\n";

            echo "       </select>\n";
            echo "      </td>\n";
            echo "      <td class=\"u\"><input name=\"record[" . $r['id'] . "][content]\" value=\"" . htmlspecialchars($r['content']) . "\"></td>\n";
            echo "      <td class=\"u\"><input size=\"4\" id=\"priority_field_" . $r['id'] . "\" name=\"record[" . $r['id'] . "][prio]\" value=\"" . htmlspecialchars($r['prio']) . "\"></td>\n";
            echo "      <td class=\"u\"><input size=\"4\" name=\"record[" . $r['id'] . "][ttl]\" value=\"" . htmlspecialchars($r['ttl']) . "\"></td>\n";
        }

        if ($domain_type == "SLAVE" || $perm_content_edit == "none" || (($perm_content_edit == "own" || $perm_content_edit == "own_as_client") && $user_is_zone_owner == "0")) {
            echo "     <td>&nbsp;</td>\n";
        } elseif ($r['type'] == "SOA" && $perm_content_edit != "all" || ($r['type'] == "NS" && $perm_content_edit == "own_as_client")) {
            echo "     <td>&nbsp;</td>\n";
        } else {
            echo "     <td>\n";
            echo "      <a class=\"btn btn-outline-primary btn-sm\" href=\"edit_record.php?id=" . $r['id'] . "&amp;domain=" . $zone_id . "\">
                                                <i class=\"bi bi-pencil-square\"></i> " . _('Edit record') . "</a>\n";
            echo "      <a class=\"btn btn-outline-danger btn-sm\" href=\"delete_record.php?id=" . $r['id'] . "&amp;domain=" . $zone_id . "\">
                                                <i class=\"bi bi-trash\"></i> " . _('Delete record') . "</a>\n";
            echo "     </td>\n";
        }

        echo "     </tr>\n";
    }

    if ($iface_zone_comments) {
        $zone_comment = '';
        $raw_zone_comment = DnsRecord::get_zone_comment($zone_id);
        if ($raw_zone_comment) { $zone_comment = htmlspecialchars($raw_zone_comment); }

        echo "    <tr>\n";
        echo "     <td>&nbsp;</td><td colspan=\"7\">Comments:</td>\n";
        echo "    </tr>\n";
        echo "    <tr>\n";
        echo "     <td>\n";
        echo "     </td>\n";
        echo "     <td colspan=\"4\"><textarea class=\"form-control form-control-sm\" rows=\"5\" cols=\"80\" name=\"comment\">" . $zone_comment . "</textarea></td>\n";
        echo "<td></td>";
        echo "     <td>";
        echo "      <a class=\"btn btn-outline-primary btn-sm\" href=\"edit_comment.php?id=" . $zone_id . "\">
                                <i class=\"bi bi-pencil-square\"></i> " . _('Edit comment') . "</a>\n";
        echo "     </td>\n";
        echo "     <tr>\n";
    }
    echo "</table>";
    echo "<table><tr>";
    echo "      <td colspan=\"7\"><br>Save as new template:</td>\n";
    echo "     </tr>\n";
    echo "     <tr>\n";
    echo "       <td colspan=\"2\">" . _('Template Name') . "</td>\n";
    echo "       <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"templ_name\" value=\"\"></td>\n";
    echo "      </tr>\n";
    echo "      <tr>\n";
    echo "       <td colspan=\"2\">" . _('Template Description') . "</td>\n";
    echo "       <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"templ_descr\" value=\"\"></td>\n";
    echo "      </tr>\n";
    echo "    </table>\n";
    echo "     <input class=\"btn btn-primary btn-sm\" type=\"submit\" name=\"commit\" value=\"" . _('Commit changes') . "\">\n";
    echo "     <input class=\"btn btn-secondary btn-sm\" type=\"reset\" name=\"reset\" value=\"" . _('Reset changes') . "\">\n";
    echo "     <input class=\"btn btn-secondary btn-sm\" type=\"submit\" name=\"save_as\" value=\"" . _('Save as template') . "\">\n";

    if ($pdnssec_use) {
        $zone_name = DnsRecord::get_domain_name_by_id($zone_id);

        if (Dnssec::dnssec_is_zone_secured($zone_name)) {
            echo "     <input class=\"btn btn-secondary btn-sm\" type=\"button\" name=\"dnssec\" onclick=\"location.href = 'dnssec.php?id=" . $zone_id . "';\" value=\"" . _('DNSSEC') . "\">\n";
            echo "     <input class=\"btn btn-secondary btn-sm\" type=\"submit\" name=\"unsign_zone\" value=\"" . _('Unsign this zone') . "\">\n";
        } else {
            echo "     <input class=\"btn btn-secondary btn-sm\" type=\"submit\" name=\"sign_zone\" value=\"" . _('Sign this zone') . "\">\n";
        }
    }

    echo "    </form>\n";
}

echo "<hr>";

if ($perm_content_edit == "all" || ($perm_content_edit == "own" || $perm_content_edit == "own_as_client") && $user_is_zone_owner == "1") {
    if ($domain_type != "SLAVE") {
        $zone_name = DnsRecord::get_domain_name_by_id($zone_id);
        echo "     <form method=\"post\" action=\"add_record.php?id=" . $zone_id . "\">\n";
        echo "      <input type=\"hidden\" name=\"domain\" value=\"" . $zone_id . "\">\n";
        echo "      <table class=\"table table-striped table-hover table-sm\">\n";
        echo "       <tr>\n";
        echo "        <td>" . _('Name') . "</td>\n";
        echo "        <td>&nbsp;</td>\n";
        echo "        <td>" . _('Type') . "</td>\n";
        echo "        <td>" . _('Content') . "</td>\n";
        echo "        <td>" . _('Priority') . "</td>\n";
        echo "        <td>" . _('TTL') . "</td>\n";
        echo "       </tr>\n";
        echo "       <tr>\n";
        echo "        <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"name\" value=\"\">." . $zone_name . "</td>\n";
        echo "        <td>IN</td>\n";
        echo "        <td>\n";
        echo "         <select class=\"form-select form-select-sm\" name=\"type\">\n";
        $found_selected_type = !(isset($type) && $type);
        $rev = "";
        foreach (RecordType::getTypes() as $record_type) {
            if (isset($type) && $type) {
                if ($type == $record_type) {
                    $add = " SELECTED";
                    $found_selected_type = true;
                } else {
                    $add = "";
                }
            } else {
                if (preg_match('/i(p6|n-addr).arpa/i', $zone_name) && strtoupper($record_type) == 'PTR') {
                    $add = " SELECTED";
                } else if ((strtoupper($record_type) == 'A') && $iface_add_reverse_record) {
                    $add = " SELECTED";
                    $rev = "<input class=\"form-check-input\" type=\"checkbox\" name=\"reverse\"><span class=\"text-secondary\">" . _('Add also reverse record') . "</span>\n";
                } else {
                    $add = "";
                }
            }
            echo "          <option" . $add . " value=\"" . htmlspecialchars($record_type) . "\">" . $record_type . "</option>\n";
        }
        if (!$found_selected_type) {
            echo "         <option SELECTED value=\"" . htmlspecialchars($type) . "\"><i>" . htmlspecialchars($type) . "</i></option>\n";
        }
        echo "         </select>\n";
        echo "        </td>\n";
        echo "        <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"content\" value=\"\"></td>\n";
        echo "        <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"prio\" value=\"\"></td>\n";
        echo "        <td><input class=\"form-control form-control-sm\" type=\"text\" name=\"ttl\" value=\"\"></td>\n";
        echo "       </tr>\n";
        echo "      </table>\n";
        echo "      <input class=\"btn btn-outline-secondary btn-sm\" type=\"submit\" name=\"commit\" value=\"" . _('Add record') . "\">\n";
        echo "      $rev";
        echo "     </form>\n";
    }
}

echo "<hr>";
echo "   <div id=\"meta\">\n";
echo "    <table>\n";
echo "     <tr>\n";
echo "      <th colspan=\"2\">" . _('Owner of zone') . "</th>\n";
echo "     </tr>\n";

$owners = DnsRecord::get_users_from_domain_id($zone_id);

if ($owners == "-1") {
    echo "      <tr><td>" . _('No owner set for this zone.') . "</td></tr>";
} else {
    if ($meta_edit) {
        foreach ($owners as $owner) {
            echo "       <tr>\n";
            echo "        <form method=\"post\" action=\"edit.php?id=" . $zone_id . "\">\n";
            echo "        <td>" . $owner["fullname"] . "</td>\n";
            echo "        <td>\n";
            echo "         <input type=\"hidden\" name=\"delete_owner\" value=\"" . $owner["id"] . "\">\n";
            echo "         <input class=\"btn btn-outline-danger btn-sm\" type=\"submit\" name=\"co\" value=\"" . _('Delete') . "\">\n";
            echo "        </td>\n";
            echo "        </form>\n";
            echo "       </tr>\n";
        }
    } else {
        foreach ($owners as $owner) {
            echo "    <tr><td>" . $owner["fullname"] . "</td><td>&nbsp;</td></tr>";
        }
    }
}
if ($meta_edit) {
    echo "      <form method=\"post\" action=\"edit.php?id=" . $zone_id . "\">\n";
    echo "       <input type=\"hidden\" name=\"domain\" value=\"" . $zone_id . "\">\n";
    echo "       <tr>\n";
    echo "        <td>\n";
    echo "         <select class=\"form-select form-select-sm\" name=\"newowner\">\n";
    /*
      Show list of users to add as owners of this domain, only if we have permission to do so.
     */
    $users = do_hook('show_users');
    foreach ($users as $user) {
        $add = '';
        if ($user["id"] == $_SESSION["userid"]) {
            echo "          <option" . $add . " value=\"" . $user["id"] . "\">" . $user["fullname"] . "</option>\n";
        } elseif ($perm_view_others == "1") {
            echo "          <option  value=\"" . $user["id"] . "\">" . $user["fullname"] . "</option>\n";
        }
    }
    echo "         </select>\n";
    echo "        </td>\n";
    echo "        <td>\n";
    echo "         <input class=\"btn btn-outline-secondary btn-sm\" type=\"submit\" name=\"co\" value=\"" . _('Add') . "\">\n";
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "      </form>\n";
}
echo "      <tr>\n";
echo "       <th colspan=\"2\">" . _('Type') . "</th>\n";
echo "      </tr>\n";

if ($meta_edit) {
    echo "      <form action=\"" . htmlentities($_SERVER['PHP_SELF'], ENT_QUOTES) . "?id=" . $zone_id . "\" method=\"post\">\n";
    echo "       <input type=\"hidden\" name=\"domain\" value=\"" . $zone_id . "\">\n";
    echo "       <tr>\n";
    echo "        <td>\n";
    echo "         <select class=\"form-select form-select-sm\" name=\"newtype\">\n";
    foreach (ZoneType::getTypes() as $type) {
        $add = '';
        if ($type == $domain_type) {
            $add = " SELECTED";
        }

        if (($perm_zone_master_add == "0" && $type == "MASTER") || ($perm_zone_slave_add == "0" && $type == "SLAVE")) {
            continue;
        }
        echo "          <option" . $add . " value=\"" . $type . "\">" . strtolower($type) . "</option>\n";
    }
    echo "         </select>\n";
    echo "        </td>\n";
    echo "        <td>\n";
    echo "         <input class=\"btn btn-outline-secondary btn-sm\" type=\"submit\" name=\"type_change\" value=\"" . _('Change') . "\">\n";
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "      </form>\n";
} else {
    echo "      <tr><td>" . strtolower($domain_type) . "</td><td>&nbsp;</td></tr>\n";
}

echo "      <tr>\n";
echo "       <th colspan=\"2\">" . _('Template') . "</th>\n";
echo "      </tr>\n";

if ($meta_edit) {
    echo "      <form action=\"" . htmlentities($_SERVER['PHP_SELF'], ENT_QUOTES) . "?id=" . $zone_id . "\" method=\"post\">\n";
    echo "       <input type=\"hidden\" name=\"current_zone_template\" value=\"" . $zone_template_id . "\">\n";
    echo "       <tr>\n";
    echo "        <td>\n";
    echo "         <select class=\"form-select form-select-sm\" name=\"zone_template\">\n";
    echo "          <option value=\"none\">none</option>\n";
    foreach ($zone_templates as $zone_template) {
        $add = '';
        if ($zone_template['id'] == $zone_template_id) {
            $add = " SELECTED";
        }
        echo "          <option .  $add . value=\"" . $zone_template['id'] . "\">" . $zone_template['name'] . "</option>\n";
    }
    echo "         </select>\n";
    echo "        </td>\n";
    echo "        <td>\n";
    echo "         <input class=\"btn btn-outline-secondary btn-sm\" type=\"submit\" name=\"template_change\" value=\"" . _('Change') . "\">\n";
    echo "        </td>\n";
    echo "       </tr>\n";
    echo "      </form>\n";
} else {
    $zone_template_details = ZoneTemplate::get_zone_templ_details($zone_template_id);
    echo "      <tr><td>" . ($zone_template_details ? strtolower($zone_template_details['name']) : "none") . "</td><td>&nbsp;</td></tr>\n";
}

if ($domain_type == "SLAVE") {
    $slave_master = DnsRecord::get_domain_slave_master($zone_id);
    echo "      <tr>\n";
    echo "       <th colspan=\"2\">" . _('IP address of master NS') . "</th>\n";
    echo "      </tr>\n";

    if ($meta_edit) {
        echo "      <form action=\"" . htmlentities($_SERVER['PHP_SELF'], ENT_QUOTES) . "?id=" . $zone_id . "\" method=\"post\">\n";
        echo "       <input type=\"hidden\" name=\"domain\" value=\"" . $zone_id . "\">\n";
        echo "       <tr>\n";
        echo "        <td>\n";
        echo "         <input type=\"text\" name=\"new_master\" value=\"" . $slave_master . "\">\n";
        echo "        </td>\n";
        echo "        <td>\n";
        echo "         <input type=\"submit\" name=\"slave_master_change\" value=\"" . _('Change') . "\">\n";
        echo "        </td>\n";
        echo "       </tr>\n";
        echo "      </form>\n";
    } else {
        echo "      <tr><td>" . $slave_master . "</td><td>&nbsp;</td></tr>\n";
    }
}
echo "     </table>\n";
echo "   </div>\n";

include_once("inc/footer.inc.php");
