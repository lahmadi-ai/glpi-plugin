
<?php
/**
 LICENSE

Copyright (C) 2016 Teclib'
Copyright (C) 2010-2016 by the FusionInventory Development Team.

This file is part of Flyve MDM Plugin for GLPI.

Flyve MDM Plugin for GLPi is a subproject of Flyve MDM. Flyve MDM is a mobile 
device management software. 

Flyve MDM Plugin for GLPI is free software: you can redistribute it and/or 
modify it under the terms of the GNU Affero General Public License as published
by the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
Flyve MDM Plugin for GLPI is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU Affero General Public License for more details.
You should have received a copy of the GNU Affero General Public License
along with Flyve MDM Plugin for GLPI. If not, see http://www.gnu.org/licenses/.
 ------------------------------------------------------------------------------
 @author    Thierry Bugier Pineau
 @copyright Copyright (c) 2016 Flyve MDM plugin team
 @license   AGPLv3+ http://www.gnu.org/licenses/agpl.txt
 @link      https://github.com/flyvemdm/backend
 @link      http://www.glpi-project.org/
 ------------------------------------------------------------------------------
*/

class PluginStorkmdmPolicyRemoveFileIntegrationTest extends RegisteredUserTestCase {

   public function testInitCreateFile() {
      global $DB;

      // Create a file (directly in DB)
      $fileName = 'flyve-user-manual.pdf';
      $fileTable = PluginStorkmdmFile::getTable();
      $entityId = $_SESSION['glpiactive_entity'];
      $query = "INSERT INTO $fileTable (
      `name`,
      `source`,
      `entities_id`
      )
      VALUES (
      '$fileName',
      '2/12345678_flyve-user-manual.pdf',
      '$entityId'
      )";
      $DB->query($query);
      $mysqlError = $DB->error();
      $file = new PluginStorkmdmFile();
      $this->assertTrue($file->getFromDBByQuery("WHERE `name`='$fileName'"), $mysqlError);

      return $file;
   }

   public function testInitGetDestination() {
      return "%SDCARD%/path/to/file.pdf";
   }

   public function testInitCreateFleet() {
      // Create a fleet
      $entityId = $_SESSION['glpiactive_entity'];
      $fleet = new PluginStorkmdmFleet();
      $fleet->add([
            'name'            => 'managed fleet',
            'entities_id'     => $entityId,
      ]);
      $this->assertFalse($fleet->isNewItem());

      return $fleet;
   }

   public function testGetPolicyData() {
      $policyData = new PluginStorkmdmPolicy();
      $this->assertTrue($policyData->getFromDBBySymbol('removeFile'));

      return $policyData;
   }

   /**
    * @depends testGetPolicyData
    */
   public function testGetRemoveFilePolicy($policyData) {
      $policyFactory = new PluginStorkmdmPolicyFactory();
      $policy = $policyFactory->createFromPolicy($policyData);
      $this->assertInstanceOf('PluginStorkmdmPolicyRemovefile', $policy);

      return $policy;
   }

   /**
    * @depends testInitCreateFleet
    * @depends testGetPolicyData
    * @depends testInitCreateFile
    * @depends testInitGetDestination
    */
   public function testApplyPolicyIncomplete(PluginStorkmdmFleet $fleet, PluginStorkmdmPolicy $policyData, PluginStorkmdmFile $file, $destination) {
      $fleet_policy = new PluginStorkmdmFleet_Policy();
      $fleet_policy->add([
            'plugin_storkmdm_policies_id' => $policyData->getID(),
            'value'                       => $destination
      ]);
      $this->assertTrue($fleet_policy->isNewItem());
   }

   /**
    * @depends testInitCreateFleet
    * @depends testGetPolicyData
    * @depends testInitCreateFile
    * @depends testInitGetDestination
    */
   public function testApplyPolicyWithBadValue(PluginStorkmdmFleet $fleet, PluginStorkmdmPolicy $policyData, PluginStorkmdmFile $file, $destination) {
      $fleet_policy = new PluginStorkmdmFleet_Policy();
      $fleet_policy->add([
            'plugin_storkmdm_fleets_id'   => $fleet->getID(),
            'plugin_storkmdm_policies_id' => '-1',
            'value'                       => $destination
      ]);
      $this->assertTrue($fleet_policy->isNewItem());
   }

   /**
    * @depends testInitCreateFleet
    * @depends testGetPolicyData
    * @depends testInitCreateFile
    * @depends testInitGetDestination
    */
   public function testApplyPolicy(PluginStorkmdmFleet $fleet, PluginStorkmdmPolicy $policyData, PluginStorkmdmFile $file, $destination) {
      $fleet_policy = new PluginStorkmdmFleet_Policy();

      // Prepare subscriber
      $mqttSubscriber = new MqttClientHandler();
      $publishedMessage = null;

      $addSuccess = null;

      $cronTask = new CronTask();
      $cronTask->getFromDBbyName("PluginStorkmdmMqttupdatequeue", "UpdateTopics");
      $cronTask->update(['id' => $cronTask->getID(), 'lastrun' => null]);

      // function to trigger the mqtt message
      $sendMqttMessageCallback = function () use (&$fleet_policy, &$policyData, &$file, &$fleet, &$addSuccess, &$destination) {
         $addSuccess = $fleet_policy->add([
               'plugin_storkmdm_fleets_id'   => $fleet->getID(),
               'plugin_storkmdm_policies_id' => $policyData->getID(),
               'value'                       => $destination
         ]);
         PluginStorkmdmMqttupdatequeue::setDelay("PT0S");
         CronTask::launch(CronTask::MODE_EXTERNAL, 1, 'UpdateTopics');
      };

      // Callback each time the mqtt broker sends a pingresp
      $callback = function () use (&$publishedMessage, &$mqttSubscriber) {
         $publishedMessage = $mqttSubscriber->getPublishedMessage();
      };

      $groupName = $policyData->getField('group');
      $mqttSubscriber->setSendMqttMessageCallback($sendMqttMessageCallback);
      $mqttSubscriber->setPingCallback($callback);
      $topic = $fleet->getTopic();
      $mqttSubscriber->subscribe("$topic/$groupName");

      $this->assertGreaterThan(0, $addSuccess, 'Failed to apply the policy');
      $this->assertInstanceOf('\sskaje\mqtt\Message\PUBLISH', $publishedMessage);

      return $publishedMessage;
   }

   /**
    * @depends testApplyPolicy
    * @param \sskaje\mqtt\Message\PUBLISH $publishedMessage
    */
   public function testMessageIsJson(\sskaje\mqtt\Message\PUBLISH $publishedMessage) {
      $message = $publishedMessage->getMessage();
      $this->assertJson($message);

      return json_decode($message, JSON_OBJECT_AS_ARRAY);
   }

   /**
    * @depends testMessageIsJson
    * @depends testInitCreateFile
    * @depends testInitGetDestination
    */
   public function testMessageContent(array $message, PluginStorkmdmFile $file, $destination) {
      $expected = [
            'file' => [
                  0 => [
                        'removeFile'    => $destination
                  ]
            ]
      ];
      $this->assertArraySubset($expected, $message);
   }
}