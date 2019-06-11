<?php
/*
 +--------------------------------------------------------------------------+
 | Copyright IT Bliss LLC (c) 2014                                          |
 +--------------------------------------------------------------------------+
 | This program is free software: you can redistribute it and/or modify     |
 | it under the terms of the GNU Affero General Public License as published |
 | by the Free Software Foundation, either version 3 of the License, or     |
 | (at your option) any later version.                                      |
 |                                                                          |
 | This program is distributed in the hope that it will be useful,          |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the            |
 | GNU Affero General Public License for more details.                      |
 |                                                                          |
 | You should have received a copy of the GNU Affero General Public License |
 | along with this program.  If not, see <http://www.gnu.org/licenses/>.    |
 +--------------------------------------------------------------------------+
*/

define('L10N_UPDATE_DOMAIN', 'com.cividesk.l10n.update');
define('L10N_UPDATE_TSNAME', ts('Localization Update extension', array('domain' => L10N_UPDATE_DOMAIN)));

/**
 * Implementation of hook_civicrm_buildForm
 */
function l10nupdate_civicrm_buildForm($formName, &$form) {
  // Administer / Localization / Languages, Currency, Locations
  if ($formName == 'CRM_Admin_Form_Setting_Localization') {
    // Replace the drop-down list of locales with all possible locales
    if ($element = $form->getElement('lcMessages')) {
      // Mostly copied from CRM_Admin_Form_Setting_Localization::buildQuickForm()
      $locales = CRM_Contact_BAO_Contact::buildOptions('preferred_language');
      $domain = new CRM_Core_DAO_Domain();
      $domain->find(TRUE);
      if ($domain->locales) {
        // for multi-lingual sites, populate default language drop-down with available languages
        $lcMessages = array();
        foreach ($locales as $loc => $lang) {
          if (substr_count($domain->locales, $loc)) {
            $lcMessages[$loc] = $lang;
          }
        }
        $form->addElement('select', 'lcMessages', ts('Default Language'), $lcMessages);
        $form->addElement('select', 'addLanguage', ts('Add Language'), array_merge(array('' => ts('- select -')), array_diff($locales, $lcMessages)));
      } else {
        // for single-lingual sites, populate default language drop-down with all languages
        $form->addElement('select', 'lcMessages', ts('Default Language'), $locales);
      }
    }
    // Refresh localization files, but if the form has been submitted by the user
    // then add the new language requested to the list of locales to be refreshed
    $locale = (!empty($_REQUEST['addLanguage']) ? $_REQUEST['addLanguage'] : (!empty($_REQUEST['lcMessages']) ? $_REQUEST['lcMessages'] : ''));
    _l10nupdate_fetch($locale);
  }
}

/**
 * Implementation of hook_civicrm_pageRun
 */
function l10nupdate_civicrm_pageRun( &$page ) {
  // Administer / System Settings / Manage Extensions
  if (is_a($page, 'CRM_Admin_Page_Extensions')) {
    // Refresh localization files
    _l10nupdate_fetch();
  }
}

/**
 * Fetches updated localization files from civicrm.org
 * it refreshes core and all extension localization files
 * for all needed languages (ie. default language in singlelingual or all enabled in multilingual)
 * @input string Comma-delimited list of additional languages that need to be fetched
 */
function _l10nupdate_fetch($locales = '') {
  $config = CRM_Core_Config::singleton();

  // Check that the l10n directory is configured, exists, and is writable
  $l10n = CRM_Core_I18n::getResourceDir();
  if (empty($l10n)) {
    CRM_Core_Session::setStatus(
      ts('Your localization directory is not configured.', array('domain' => L10N_UPDATE_DOMAIN)),
      L10N_UPDATE_TSNAME, 'error'
    );
    return;
  }
  if (!is_dir($l10n) || !is_writable($l10n)) {
    CRM_Core_Session::setStatus(
      ts('Your localization directory, %1, is not writable.', array(1 => $l10n, 'domain' => L10N_UPDATE_DOMAIN)),
      L10N_UPDATE_TSNAME, 'error'
    );
    return;
  }

  // Get the list of locales we need to download
  // start from the input parameter
  $locales = ($locales ? explode(',', $locales) : array());
  $domain = new CRM_Core_DAO_Domain;
  $domain->find(TRUE);
  if ($domain->locales) {
    // in multilingual mode, add list of enabled locales
    $locales = array_merge($locales, explode(CRM_Core_DAO::VALUE_SEPARATOR, $domain->locales));
  } else {
    // in singlelingual mode, add default locale
    $locales = array_merge($locales, array($config->lcMessages));
  }

  // Now download the l10n files from civicrm.org
  $downloaded = array();
  foreach ($locales as $locale) {
    if ($locale == 'en_US') continue;
    // sanity tests - does the locale look legit?
    if ((strlen($locale) != 5) || (substr($locale, 2,1) != '_')) continue;

    // Download core translation files
    $subdir = '/LC_MESSAGES';
    if (!is_dir($l10n . $locale . $subdir)) {
      $subdir = '';
    }
    $remoteURL = "https://download.civicrm.org/civicrm-l10n-core/mo/$locale/civicrm.mo";
    $localFile = $l10n . $locale . $subdir . '/civicrm.mo';
    if (_l10nupdate_download($remoteURL, $localFile)) {
      $downloaded['core'] = 1;
    }

    // Download extensions translation files
    foreach (CRM_Core_PseudoConstant::getModuleExtensions() as $module) {
      $extname = $module['prefix'];
      $extroot = dirname($module['filePath']);
      $remoteURL = "https://download.civicrm.org/civicrm-l10n-extensions/mo/$extname/$locale/$extname.mo";
      $localFile = "$extroot/l10n/$locale/LC_MESSAGES/$extname.mo";
      if (_l10nupdate_download($remoteURL, $localFile)) {
        $downloaded[$extname] = 1;
      }
    }

    // Output a nicely formatted message if we have been successful
    if (!empty($downloaded)) {
      $modules = array_keys($downloaded);
      if (sizeof($modules) > 1) {
        $last = array_shift($modules);
        $list = implode(', ', $modules).' '.ts('and', array('domain' => L10N_UPDATE_DOMAIN)).' '.$last;
      } else {
        $list = reset($modules);
      }
      CRM_Core_Session::setStatus(
        ts('Your localization files for %1 have been updated.', array(1 => $list, 'domain' => L10N_UPDATE_DOMAIN)),
        L10N_UPDATE_TSNAME, 'success'
      );
    }
  }
}

/**
 * Downloads a particular localization files from civicrm.org
 * will check that we have not already downloaded it recently
 * @param string $remoteURL URL for this particular file
 * @param string $localFile where to store this file locally
 * @return boolean true if the file was refreshed and is not empty
 */
function _l10nupdate_download($remoteURL, $localFile) {
  $delay = strtotime("1 day");
  if ((!file_exists($localFile)) || ((time() - filemtime($localFile)) > $delay)) {
    if (!@mkdir(dirname($localFile), 0775, true)) {
      return false;
    }
    $result = CRM_Utils_HttpClient::singleton()->fetch($remoteURL, $localFile);
    if (($result == CRM_Utils_HttpClient::STATUS_OK) && file_exists($localFile)) {
      // Check if CRM_Utils_HttpClient encountered a HTTP error 404 (cf. CRM-14649)
      if (strpos(file_get_contents($localFile), '404 Not Found')) {
        // reset the file to empty (then will not try to reload until delay is passed)
        fclose(fopen($localFile, 'w'));
        return false;
      }
      return true;
    }
  }
  return false;
}
