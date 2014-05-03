<?php
/*
 +--------------------------------------------------------------------------+
 | Copyright IT Bliss LLC (c) 2012-2013                                     |
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

define('L10N_AUTOFETCH', 'com.cividesk.l10n.autofetch');

/**
 * Implementation of hook_civicrm_buildForm
 */
function autofetch_civicrm_buildForm($formName, &$form) {
  // Administer / Localization / Languages, Currency, Locations
  if ($formName == 'CRM_Admin_Form_Setting_Localization') {
    // Refresh localization files for core
    _autofetch_fetch();
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
  }
}

/**
 * Implementation of hook_civicrm_postProcess
 */
function autofetch_civicrm_postProcess( $formName, &$form ) {
  // Administer / Localization / Languages, Currency, Locations
  if ($formName == 'CRM_Admin_Form_Setting_Localization') {
    // Refresh localization files for core
    _autofetch_fetch();
  }
}

/**
 * Implementation of hook_civicrm_pageRun
 */
function autofetch_civicrm_pageRun( &$page ) {
  // Administer / System Settings / Manage Extensions
  if (is_a($page, 'CRM_Admin_Page_Extensions')) {
    // Refresh localization files for extensions
    _autofetch_fetch();
  }
}

/**
 * Fetches updated localization files from civicrm.org
 * it refreshes core and all extension localization files
 * for all needed languages (ie. default language in singlelingual or all enabled in multilingual)
 */
function _autofetch_fetch() {
  global $_autofetch_done;

  // Have we been already called by another hook?
  if ($_autofetch_done) {
    return;
  }
  $_autofetch_done = true;

  $config = CRM_Core_Config::singleton();

  // Check that the l10n directory is configured, exists, and is writable
  $l10n = $config->gettextResourceDir;
  if (empty($l10n)) {
    CRM_Core_Session::setStatus(
      ts('Your localization directory is not configured.', array('domain' => L10N_AUTOFETCH)),
      ts('Localization update', array('domain' => L10N_AUTOFETCH)),
      'error'
    );
    return;
  }
  if (!is_dir($l10n) || !is_writable($l10n)) {
    CRM_Core_Session::setStatus(
      ts('Your localization directory, %1, is not writable.', array(1 => $l10n, 'domain' => L10N_AUTOFETCH)),
      ts('Localization update', array('domain' => L10N_AUTOFETCH)),
      'error'
    );
    return;
  }

  // Get the list of locales we need to download
  $domain = civicrm_api('Domain', 'get', array(
    'current_domain' => 1,
  ));

  if ($domain['is_error']) {
    CRM_Core_Error::fatal(ts('Could not find the domain information using the API.', array('domain' => L10N_AUTOFETCH)));
  }

  $domain_id = $domain['id'];

  if (! empty($domain['values'][$domain]['locales'])) {
    // We are in multilingual mode, use list of enabled locale
    $locales = $domain['values'][$domain]['locales'];
  }
  else {
    // We are in singlelingual mode, use form-submitted locale or existing locale
    $locales = array(CRM_Utils_System::getUFLocale());
  }

  // Now download the l10n files from civicrm.org
  $downloaded = array();
  foreach ($locales as $locale) {
    if ($locale == 'en_US') continue;

    // Download core translation files
    $subdir = '/LC_MESSAGES';
    if (!is_dir($l10n . $locale . $subdir)) {
      $subdir = '';
    }
    $remoteURL = "https://download.civicrm.org/civicrm-l10n-core/mo/$locale/civicrm.mo";
    $localFile = $l10n . $locale . $subdir . '/civicrm.mo';
    if (_autofetch_download($remoteURL, $localFile)) {
      $downloaded['core'] = 1;
    }

    // Download extensions translation files
    foreach (CRM_Core_PseudoConstant::getModuleExtensions() as $module) {
      $extname = $module['prefix'];
      $extroot = dirname($module['filePath']);
      $remoteURL = "https://download.civicrm.org/civicrm-l10n-extensions/mo/$extname/$locale/$extname.mo";
      $localFile = "$extroot/l10n/$locale/LC_MESSAGES/$extname.mo";
      if (_autofetch_download($remoteURL, $localFile)) {
        $downloaded[$extname] = 1;
      }
    }

    // Output a nicely formatted message if we have been successful
    if (!empty($downloaded)) {
      $modules = array_keys($downloaded);
      if (sizeof($modules) > 1) {
        $last = array_shift($modules);
        $list = explode(', ', $modules).' '.ts('and', array('domain' => L10N_AUTOFETCH)).' '.$last;
      } else {
        $list = reset($modules);
      }
      CRM_Core_Session::setStatus(
        ts('Your localization files for %1 have been updated.', array(1 => $list, 'domain' => L10N_AUTOFETCH)),
        ts('Localization update', array('domain' => L10N_AUTOFETCH)),
        'success'
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
function _autofetch_download($remoteURL, $localFile) {
  $delay = strtotime("1 day");
  if ((!file_exists($localFile)) || ((time() - filemtime($localFile)) > $delay)) {
    if (! @mkdir(dirname($localFile), 0775, true)) {
      CRM_Core_Session::setStatus(
        ts('Could not create the directory for localization files (%1). Please create it manually and allow the web server to write to it.', array(1 => $localFile, 'domain' => L10N_AUTOFETCH)),
        ts('Localization update', array('domain' => L10N_AUTOFETCH)),
        'error'
      );
      return false;
    }

    $result = CRM_Utils_HttpClient::singleton()->fetch($remoteURL, $localFile);
    if (($result == CRM_Utils_HttpClient::STATUS_OK) && file_exists($localFile)) {
      return (filesize($localFile) > 0);
    }
  }
  return false;
}