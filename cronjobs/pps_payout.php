#!/usr/bin/php
<?php

/*

Copyright:: 2013, Sebastian Grewe

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.

 */

// Include all settings and classes
require_once('shared.inc.php');

// Fetch all unaccounted blocks
$aAllBlocks = $block->getAllUnaccounted('ASC');
if (empty($aAllBlocks)) {
  verbose("No new unaccounted blocks found\n");
  exit(0);
}

$count = 0;
foreach ($aAllBlocks as $iIndex => $aBlock) {
  if (!$aBlock['accounted']) {
    if ($share->setUpstream(@$aAllBlocks[$iIndex - 1]['time'])) {
      $share->setLastUpstreamId();
    }

    if ($share->setUpstream($aBlock['time'])) {
      $iCurrentUpstreamId = $share->getUpstreamId();
    } else {
      verbose("Unable to fetch blocks upstream share\n");
      verbose($share->getError() . "\n");
      continue;
    }
    $aAccountShares = $share->getSharesForAccounts($share->getLastUpstreamId(), $iCurrentUpstreamId);
    $iRoundShares = $share->getRoundShares($share->getLastUpstreamId(), $iCurrentUpstreamId);
    verbose("ID\tHeight\tTime\t\tShares\tFinder\t\tShare ID\tPrev Share\t\tStatus\n");
    verbose($aBlock['id'] . "\t" . $aBlock['height'] . "\t" . $aBlock['time'] . "\t" . $iRoundShares . "\t" . $share->getUpstreamFinder() . "\t" . $share->getUpstreamId() . "\t\t" . $share->getLastUpstreamId());
    if (empty($aAccountShares)) {
      verbose("\nNo shares found for this block\n\n");
      sleep(2);
      continue;
    }
    $strStatus = "OK";
    if (!$block->setFinder($aBlock['id'], $user->getUserId($share->getUpstreamFinder())))
      $strStatus = "Finder Failed";
    if (!$block->setShares($aBlock['id'], $iRoundShares))
      $strStatus = "Shares Failed";
    verbose("\t\t$strStatus\n\n");
    verbose("ID\tUsername\tValid\tInvalid\tPercentage\tPayout\t\tDonation\tFee\t\tStatus\n");
    foreach ($aAccountShares as $key => $aData) {
      // Payout based on shares, PPS system
      $aData['percentage'] = number_format(round(( 100 / $iRoundShares ) * $aData['valid'], 8), 8);
      $aData['payout'] = number_format(round(( $aData['percentage'] / 100 ) * $config['reward'], 8), 8);
      // Defaults
      $aData['fee' ] = 0;
      $aData['donation'] = 0;

      if ($config['fees'] > 0)
        $aData['fee'] = number_format(round($config['fees'] / 100 * $aData['payout'], 8), 8);
      // Calculate donation amount, fees not included
      $aData['donation'] = number_format(round($user->getDonatePercent($user->getUserId($aData['username'])) / 100 * ( $aData['payout'] - $aData['fee']), 8), 8);

      // Verbose output of this users calculations
      verbose($aData['id'] . "\t" .
           $aData['username'] . "\t" .
           $aData['valid'] . "\t" .
           $aData['invalid'] . "\t" .
           $aData['percentage'] . "\t" .
           $aData['payout'] . "\t" .
           $aData['donation'] . "\t" .
           $aData['fee'] . "\t");

      $strStatus = "OK";
      // Update user share statistics
      if (!$statistics->updateShareStatistics($aData, $aBlock['id']))
        $strStatus = "Stats Failed";
      // Add new credit transaction
      if (!$transaction->addTransaction($aData['id'], $aData['payout'], 'Credit', $aBlock['id']))
        $strStatus = "Transaction Failed";
      // Add new fee debit for this block
      if ($aData['fee'] > 0 && $config['fees'] > 0)
        if (!$transaction->addTransaction($aData['id'], $aData['fee'], 'Fee', $aBlock['id']))
          $strStatus = "Fee Failed";
      // Add new donation debit
      if ($aData['donation'] > 0)
        if (!$transaction->addTransaction($aData['id'], $aData['donation'], 'Donation', $aBlock['id']))
          $strStatus = "Donation Failed";
      verbose("\t$strStatus\n");
    }
    verbose("------------------------------------------------------------------------\n\n");

    // Move counted shares to archive before this blockhash upstream share
    $share->moveArchive($share->getLastUpstreamId(), $iCurrentUpstreamId, $aBlock['id']);
    $block->setAccounted($aBlock['id']);
  }
}
