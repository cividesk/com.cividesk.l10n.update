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

require_once 'l10nupdate.civix.php';
use CRM_L10nupdate_ExtensionUtil as E;

define('L10N_UPDATE_DOMAIN', 'com.cividesk.l10n.update');
define('L10N_UPDATE_TSNAME', E::ts('Localization Update extension'));

/**
 * Implements hook_civicrm_config().
 */
function l10nupdate_civicrm_config(&$config) {
  _l10nupdate_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_buildForm
 * @param string $formName
 * @param \CRM_Core_Form $form
 *
 * @throws \CRM_Core_Exception
 */
function l10nupdate_civicrm_buildForm($formName, &$form) {
  // Administer / Localization / Languages, Currency, Locations
  if ($formName == 'CRM_Admin_Form_Setting_Localization') {
    // Replace the drop-down list of locales with all possible locales
    if ($form->getElement('lcMessages')) {
      // Mostly copied from CRM_Admin_Form_Setting_Localization::buildQuickForm()
      $locales = CRM_Contact_BAO_Contact::buildOptions('preferred_language');
      $domain = new CRM_Core_DAO_Domain();
      $domain->find(TRUE);
      // Populate default language drop-down with available languages
      $lcMessages = array();
      foreach ($locales as $loc => $lang) {
        if (substr_count($domain->locales, $loc)) {
          $lcMessages[$loc] = $lang;
        }
      }
      $form->addElement('select', 'lcMessages', E::ts('Default Language'), $locales);
      $form->addElement('select', 'addLanguage', E::ts('Add Language'), array_merge(['' => E::ts('- select -')], array_diff($locales, $lcMessages)));
      // This replaces the uiLanguages select element with one which has all available languages even if they are not already downloaded.
      // If you enable a language this extension will download it.
      $uiLanguagesSetting = \Civi\Core\SettingsMetadata::getMetadata(['name' => ['uiLanguages']], NULL, TRUE)['uiLanguages'];
      $uiLanguagesSetting['options'] = $locales;
      $uiLanguagesSetting['html_attributes']['class'] = $uiLanguagesSetting['html_attributes']['class'] . ' big';
      $form->add($uiLanguagesSetting['html_type'], $uiLanguagesSetting['name'], $uiLanguagesSetting['title'], $uiLanguagesSetting['options'], $uiLanguagesSetting['is_required'] ?? FALSE, $uiLanguagesSetting['html_attributes']);
    }
    // Refresh localization files, but if the form has been submitted by the user
    // then add the new language requested to the list of locales to be refreshed
    $locale = $_REQUEST['addLanguage'] ?? $_REQUEST['lcMessages'] ?? '';
    l10nupdate_fetch($locale);
  }
}

/**
 * Implementation of hook_civicrm_pageRun
 *
 * @param \CRM_Core_Page $page
 *
 * @throws \CRM_Core_Exception
 */
function l10nupdate_civicrm_pageRun(&$page) {
  // Administer / System Settings / Manage Extensions
  if (is_a($page, 'CRM_Admin_Page_Extensions')) {
    // Refresh localization files
    l10nupdate_fetch();
  }
}

/**
 * Fetches updated localization files from civicrm.org
 * it refreshes core and all extension localization files
 * for all needed languages (ie. default language in singlelingual or all enabled in multilingual)
 *
 * @param string $locales Comma-delimited list of additional languages that need to be fetched
 * @param bool $forceDownload If true, re-download even if we already downloaded within the last day
 *
 * @return array|void
 * @throws \CRM_Core_Exception
 */
function l10nupdate_fetch($locales = '', $forceDownload = FALSE) {
  $config = CRM_Core_Config::singleton();

  // Check that the l10n directory is configured, exists, and is writable
  $l10n = CRM_Core_I18n::getResourceDir();
  if (empty($l10n)) {
    CRM_Core_Session::setStatus(
      E::ts('Your localization directory is not configured.', ['domain' => L10N_UPDATE_DOMAIN]),
      L10N_UPDATE_TSNAME, 'error'
    );
    return;
  }
  if (!is_dir($l10n) || !is_writable($l10n)) {
    CRM_Core_Session::setStatus(
      E::ts('Your localization directory, %1, is not writable.', [1 => $l10n]),
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
    // in singlelingual mode, add enabled locales
    $locales = array_merge(CRM_Core_I18n::uiLanguages(TRUE), array($config->lcMessages));
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

    try {
      $remoteURL = "https://download.civicrm.org/civicrm-l10n-core/mo/$locale/civicrm.mo";
      $localFile = $l10n . $locale . $subdir . '/civicrm.mo';
      if (_l10nupdate_download($remoteURL, $localFile, $forceDownload)) {
        $downloaded['core']++;
      }

      // Download extensions translation files
      foreach (CRM_Core_PseudoConstant::getModuleExtensions() as $module) {
        $extname = $module['prefix'];
        $extroot = dirname($module['filePath']);
        $remoteURL = "https://download.civicrm.org/civicrm-l10n-extensions/mo/$extname/$locale/$extname.mo";
        $localFile = "$extroot/l10n/$locale/LC_MESSAGES/$extname.mo";
        if (_l10nupdate_download($remoteURL, $localFile, $forceDownload)) {
          $downloaded[$extname]++;
        }
      }
    }
    catch (GuzzleHttp\Exception\ConnectException $e) {
      \Civi::log('l10nupdate')->error("l10nupdate_download Aborting (ConnectException {$remoteURL}): " . $e->getMessage());
      // Do not try to download any more locales. ConnectException is probably fatal.
      break;
    }
    catch (Exception $e) {
      \Civi::log('l10nupdate')->error("l10nupdate_download failed: " . $e->getMessage());
      // Do not try to download any more locales.
      break;
    }

    // Output a nicely formatted message if we have been successful
    if (!empty($downloaded)) {
      $modules = array_keys($downloaded);
      if (sizeof($modules) > 1) {
        $last = array_shift($modules);
        $list = implode(', ', $modules) . ' ' . E::ts('and') . ' ' . $last;
      } else {
        $list = reset($modules);
      }
      CRM_Core_Session::setStatus(
        E::ts('Your localization files for %1 have been updated.', [1 => $list]),
        L10N_UPDATE_TSNAME, 'success'
      );
    }
  }
  return $downloaded;
}

/**
 * Downloads a particular localization files from civicrm.org
 * will check that we have not already downloaded it recently
 * @param string $remoteURL URL for this particular file
 * @param string $localFile where to store this file locally
 * @param bool $forceDownload If TRUE, force re-download
 *
 * @return boolean true if the file was refreshed and is not empty
 * @throws \CRM_Core_Exception
 */
function _l10nupdate_download($remoteURL, $localFile, $forceDownload = FALSE) {
  $delay = strtotime("1 day");
  $l10nFileOutOfDate = ((time() - @filemtime($localFile)) > $delay);

  if ((!file_exists($localFile)) || $l10nFileOutOfDate || $forceDownload) {
    $localeDir = dirname($localFile);
    if (!is_dir($localeDir) && !@mkdir($localeDir, 0775, TRUE)) {
      return FALSE;
    }
    $client = new GuzzleHttp\Client();
    $response = $client->request('GET', $remoteURL, ['sink' => $localFile, 'timeout' => 5, 'http_errors' => FALSE]);
    if ($response->getStatusCode() !== 200) {
      \Civi::log()->warning($response->getStatusCode() . ': ' . $response->getReasonPhrase() . ': ' . $remoteURL);
      // reset the file to empty (then will not try to reload until delay is passed)
      fclose(fopen($localFile, 'w'));
      return FALSE;
    }
    if (($response->getStatusCode() === 200) && file_exists($localFile)) {
      return TRUE;
    }
  }

  return FALSE;
}
