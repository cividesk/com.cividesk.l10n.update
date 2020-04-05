<?php

/**
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_l10nupdate_fetch($params) {
  l10nupdate_fetch();
  return civicrm_api3_create_success([], $params, 'L10nupdate', 'Fetch');
}
