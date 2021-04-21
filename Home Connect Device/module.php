<?php

declare(strict_types=1);
    class HomeConnectDevice extends IPSModule
    {
        const RESTRICTIONS = [
            'BSH.Common.Status.RemoteControlStartAllowed',
            'BSH.Common.Status.RemoteControlActive',
            'BSH.Common.Status.LocalControlActive'
        ];

        const EXCLUDE = [
            //StartInRelative is not selectable
            'BSH.Common.Option.StartInRelative',
            'BSH.Common.Root.ActiveProgram'
        ];

        //Options which are not real options but a kind of 'status updates'
        const UPDATE_OPTIONS = [
            'BSH.Common.Option.ProgramProgress',
            'BSH.Common.Option.RemainingProgramTime'
        ];
        public function Create()
        {
            //Never delete this line!
            parent::Create();

            $this->ConnectParent('{CE76810D-B685-9BE0-CC04-38B204DEAD5E}');

            $this->RegisterPropertyString('HaID', '');
            $this->RegisterPropertyString('DeviceType', '');

            $this->RegisterAttributeString('Settings', '[]');
            $this->RegisterAttributeString('OptionKeys', '[]');

            //Restrictions
            $this->RegisterAttributeString('Restrictions', '');

            //Update Options
            if (!IPS_VariableProfileExists('HomeConnect.Common.Option.ProgramProgress')) {
                IPS_CreateVariableProfile('HomeConnect.Common.Option.ProgramProgress', VARIABLETYPE_INTEGER);
                IPS_SetVariableProfileText('HomeConnect.Common.Option.ProgramProgress', '', ' ' . '%');
            }

            if (!IPS_VariableProfileExists('HomeConnect.Common.Option.RemainingProgramTime')) {
                IPS_CreateVariableProfile('HomeConnect.Common.Option.RemainingProgramTime', VARIABLETYPE_INTEGER);
                IPS_SetVariableProfileText('HomeConnect.Common.Option.RemainingProgramTime', '', ' ' . $this->Translate('Seconds'));
            }
        }

        public function Destroy()
        {
            //Never delete this line!
            parent::Destroy();
        }

        public function ApplyChanges()
        {
            //Never delete this line!
            parent::ApplyChanges();

            if (IPS_GetKernelRunlevel() == KR_READY) {
                if ($this->HasActiveParent()) {
                    if ($this->ReadPropertyString('HaID')) {
                        $this->SetSummary($this->ReadPropertyString('HaID'));
                        $this->createStates();
                        $this->setupSettings();
                        $this->createPrograms();
                        //If the device is inactive, we cannot retrieve information about the current selected Program
                        if (@IPS_GetObjectIDByIdent('OperationState', $this->InstanceID) && ($this->GetValue('OperationState') != 'BSH.Common.EnumType.OperationState.Inactive')) {
                            $this->updateOptionValues($this->getSelectedProgram());
                        }
                    }
                }
            }

            $this->SetReceiveDataFilter('.*' . $this->ReadPropertyString('HaID') . '.*');
        }

        public function ReceiveData($String)
        {
            $rawData = explode("\n", utf8_decode(json_decode($String, true)['Buffer']));
            $cleanData = [];
            foreach ($rawData as $entry) {
                preg_match('/(.*?):[ ]*(.*)/', $entry, $matches);
                if ($matches) {
                    $cleanData[preg_replace('/\s+/', '', $matches[1])] = preg_replace('/\s+/', '', $matches[2]);
                }
            }
            switch ($cleanData['event']) {
                case 'STATUS':
                case 'NOTIFY':
                    $items = json_decode($cleanData['data'], true)['items'];
                    $this->SendDebug($cleanData['event'], json_encode($items), 0);
                    foreach ($items as $item) {
                        if (in_array($item['key'], self::EXCLUDE)) {
                            continue;
                        }
                        $ident = $this->getLastSnippet($item['key']);
                        if (in_array($item['key'], self::RESTRICTIONS)) {
                            $this->updateRestrictions($ident, $item);
                            continue;
                        }

                        preg_match('/.+\.(?P<type>.+)\..+/m', $item['key'], $matches);
                        if ($matches) {
                            switch ($matches['type']) {
                                case 'Status':
                                    $this->createStates(['data' => ['status' => [$item]]]);
                                    break;

                                default:
                                    if (in_array($item['key'], self::UPDATE_OPTIONS)) {
                                        $this->createVariableByData($item);
                                        break;
                                    }
                                    if ($ident == 'SelectedProgram') {
                                        // $this->updateOptionValues($this->getProgram($item['value']));
                                        $this->updateOptionValues($this->getSelectedProgram());
                                    }
                                    if (strpos($item['key'], 'Option') != false) {
                                        $ident = 'Option' . $ident;
                                    }
                                    $this->SetValue($ident, $item['value']);
                                    $this->SendDebug($ident, strval($item['value']), 0);
                                    break;
                            }
                        }
                    }

            }
        }

        public function GetConfigurationForm()
        {
            $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
            return json_encode($form);
        }

        public function RequestAction($Ident, $Value)
        {
            switch ($Ident) {
                case 'SelectedProgram':
                    if (!$this->switchable()) {
                        //TODO: better error message
                        echo 'not switchable';
                        return;
                    }
                    $payload = [
                        'data' => [
                            'key'     => $Value,
                            'options' => []
                        ]
                    ];
                    $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected', json_encode($payload));
                    $this->updateOptionValues($this->getSelectedProgram());
                    break;

                case 'Control':
                    switch ($Value) {
                        case 'Start':
                            if (!$this->switchable()) {
                                return;
                            }
                            $payload = [
                                'data' => [
                                    'key'     => $this->GetValue('SelectedProgram'),
                                    'options' => $this->ReadPropertyString('DeviceType') == 'Oven' ? $this->createOptionPayload() : []
                                ]
                            ];
                            $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/active', json_encode($payload));
                            break;
                    }
                    break;

                default:
                    if (!$this->switchable()) {
                        return;
                    }
                    $availableOptions = $this->getValidOptions();
                    if (isset($availableOptions[$Ident])) {
                        $optionKeys = json_decode($this->ReadAttributeString('OptionKeys'), true);
                        $payload = [
                            'data' => [
                                'key'   => $optionKeys[$Ident],
                                'value' => $Value
                            ]
                        ];
                        $profileName = IPS_GetVariable($this->GetIDForIdent($Ident))['VariableProfile'];
                        $profile = IPS_GetVariableProfile($profileName);
                        $suffix = str_replace(' ', '', $profile['Suffix']);
                        if ($suffix) {
                            $payload['data']['unit'] = $suffix;
                        }
                        $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected/options/' . $optionKeys[$Ident], json_encode($payload));
                    }

                    $availableSettings = json_decode($this->ReadAttributeString('Settings'), true);
                    $this->SendDebug('Settings', json_encode($availableSettings), 0);
                    if (isset($availableSettings[$Ident])) {
                        $payload = [
                            'data' => [
                                'key'   => $availableSettings[$Ident]['key'],
                                'value' => $Value
                            ]
                        ];
                        $this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings/' . $availableSettings[$Ident]['key'], json_encode($payload));
                    }
                    break;
            }
            $this->SetValue($Ident, $Value);
        }

        private function createPrograms()
        {
            $rawPrograms = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/available'), true);
            if (!isset($rawPrograms['data']['programs'])) {
                return;
            }
            $programs = $rawPrograms['data']['programs'];
            $this->SendDebug('Programs', json_encode($programs), 0);
            $profileName = 'HomeConnect.' . $this->ReadPropertyString('DeviceType') . '.Programs';
            if (!IPS_VariableProfileExists($profileName)) {
                IPS_CreateVariableProfile($profileName, VARIABLETYPE_STRING);
            } else {
                //Clear profile if it exists
                foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
                    IPS_SetVariableProfileAssociation($profileName, $association['Value'], '', '', 0);
                }
            }
            foreach ($programs as $program) {
                preg_match('/(?P<program>.+)\.(?P<value>.+)/m', $program['key'], $matches);
                $displayName = isset($program['name']) ? $program['name'] : $matches['value'];
                IPS_SetVariableProfileAssociation($profileName, $program['key'], $displayName, '', -1);
            }
            $ident = 'SelectedProgram';
            $this->MaintainVariable($ident, $this->Translate('Program'), VARIABLETYPE_STRING, $profileName, 1, true);
            $this->EnableAction($ident);
        }

        private function createOptionPayload()
        {
            $availableOptions = $this->getValidOptions();
            $optionsPayload = [];
            foreach ($availableOptions as $ident => $key) {
                $optionsPayload[] = [
                    'key'   => $key,
                    'value' => $this->GetValue($ident)
                ];
            }
            return $optionsPayload;
        }

        private function updateOptionVariables($program)
        {
            $rawOptions = $this->getProgram($program);
            $this->SendDebug('RawOptions', json_encode($rawOptions), 0);
            if (!$rawOptions) {
                $this->SetValue('SelectedProgram', '');
                $this->setOptionsDisabled(true);
                return;
            }
            $this->setOptionsDisabled(false);
            $options = $rawOptions['options'];
            $position = 10;
            $availableOptions = [];
            foreach ($options as $option) {
                if (in_array($option['key'], self::EXCLUDE)) {
                    continue;
                }
                $key = $option['key'];
                preg_match('/.+\.(?P<option>.+)/m', $key, $matches);
                $ident = $matches['option'];
                $availableOptions[] = 'Option' . $ident;

                $profileName = 'HomeConnect.' . $this->ReadPropertyString('DeviceType') . '.Option.' . $ident;
                $this->createVariableFromConstraints($profileName, $option, 'Option', $position);
                $position++;
            }

            $children = IPS_GetChildrenIDs($this->InstanceID);
            $optionVariables = [];
            foreach ($children as $child) {
                $object = IPS_GetObject($child);
                if (strpos($object['ObjectIdent'], 'Option') !== false) {
                    $optionVariables[$object['ObjectIdent']] = $child;
                }
            }
            foreach ($optionVariables as $ident => $variableID) {
                IPS_SetHidden($variableID, !in_array($ident, $availableOptions));
            }

            if (!IPS_VariableProfileExists('HomeConnect.Control')) {
                IPS_CreateVariableProfile('HomeConnect.Control', VARIABLETYPE_STRING);
                IPS_SetVariableProfileAssociation('HomeConnect.Control', 'Start', $this->Translate('Start'), '', -1);
            }
            if (!@IPS_GetObjectIDByIdent('Control', $this->InstanceID)) {
                $this->MaintainVariable('Control', $this->Translate('Control'), VARIABLETYPE_STRING, 'HomeConnect.Control', $position, true);
                $this->SetValue('Control', 'Start');
                $this->EnableAction('Control');
            }
        }

        private function getSelectedProgram()
        {
            return json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected'), true)['data'];
        }

        private function getProgram($key)
        {
            $endpoint = 'homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/available/' . $key;
            $data = json_decode($this->requestDataFromParent($endpoint), true);
            return isset($data['data']) ? $data['data'] : false;
        }

        private function getOption($key)
        {
            if (in_array($key, self::EXCLUDE)) {
                return false;
            }
            $data = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/programs/selected/options/' . $key), true)['data'];
            return $data;
        }
        private function updateOptionValues($program)
        {
            $this->SetValue('SelectedProgram', $program['key']);
            $this->updateOptionVariables($program['key']);
            $optionKeys = [];
            foreach ($program['options'] as $option) {
                $ident = 'Option' . $this->getLastSnippet($option['key']);
                $optionKeys[$ident] = $option['key'];
                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                    $this->SendDebug('Value', strval($option['value']), 0);
                    $this->SetValue($ident, $option['value']);
                }
            }
            $this->WriteAttributeString('OptionKeys', json_encode($optionKeys));
        }

        private function createStates($states = '')
        {
            if (!$states) {
                $data = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/status'), true);
            } else {
                $data = $states;
            }
            $this->SendDebug('States', json_encode($data), 0);
            if (isset($data['data']['status'])) {
                foreach ($data['data']['status'] as $state) {
                    $ident = $this->getLastSnippet($state['key']);
                    //Skip remote control states and transfer to attributess
                    if (in_array($state['key'], self::RESTRICTIONS)) {
                        $this->updateRestrictions($ident, $state);
                        continue;
                    }
                    $value = $state['value'];

                    $profileName = str_replace('BSH', 'HomeConnect', $state['key']);
                    $variableType = $this->getVariableType($value);
                    if (!IPS_VariableProfileExists($profileName)) {
                        IPS_CreateVariableProfile($profileName, $variableType);
                    }
                    switch ($variableType) {
                        case VARIABLETYPE_STRING:
                            $this->addAssociation($profileName, $value, isset($state['displayvalue']) ? $state['displayvalue'] : $this->splitCamelCase($this->getLastSnippet($state['value'])));
                            break;

                        case VARIABLETYPE_INTEGER:
                            if (isset($state['unit'])) {
                                IPS_SetVariableProfileText($profileName, '', ' ' . $state['unit']);
                            }
                            break;

                        default:
                            break;

                    }
                    $variableDisplayName = isset($state['name']) ? $state['name'] : $this->splitCamelCase($ident);
                    $this->MaintainVariable($ident, $variableDisplayName, $variableType, $profileName, 0, true);
                    $this->SetValue($ident, $value);
                }
            }
        }

        private function addAssociation($profileName, $value, $name)
        {
            foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
                if ($association['Value'] == $value) {
                    return;
                }
            }
            IPS_SetVariableProfileAssociation($profileName, $value, $name, '', -1);
        }

        private function getLastSnippet($string)
        {
            return substr($string, strrpos($string, '.') + 1, strlen($string) - strrpos($string, '.'));
        }

        private function requestDataFromParent($endpoint, $payload = '')
        {
            $data = [
                'DataID'      => '{41DDAA3B-65F0-B833-36EE-CEB57A80D022}',
                'Endpoint'    => $endpoint
            ];
            if ($payload) {
                $data['Payload'] = $payload;
            }
            $response = $this->SendDataToParent(json_encode($data));
            $errorDetector = json_decode($response, true);
            if (isset($errorDetector['error'])) {
                if ($errorDetector['error']['key'] == 'SDK.UnsupportedProgram') {
                    return $response;
                }
                $this->SendDebug('ErrorPayload', $payload, 0);
                $this->SendDebug('ErrorEndpoint', $endpoint, 0);
                echo $errorDetector['error']['description'];
            }
            $this->SendDebug('requestetData', $response, 0);
            return $response;
        }

        private function createAssociations($profileName, $associations)
        {
            foreach ($associations as $association) {
                IPS_SetVariableProfileAssociation($profileName, $association['Value'], $this->Translate($association['Name']), '', -1);
            }
        }

        private function setupSettings()
        {
            $allSettings = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings'), true);
            $this->SendDebug('Settings', json_encode($allSettings), 0);
            if (isset($allSettings['data']['settings'])) {
                $availableSettings = json_decode($this->ReadAttributeString('Settings'), true);
                $position = 0;
                foreach ($allSettings['data']['settings'] as $setting) {
                    $value = $setting['value'];
                    $ident = $this->getLastSnippet($setting['key']);

                    //Add setting to available settings
                    if (!isset($availableSettings[$ident])) {
                        $availableSettings[$ident] = ['key' => $setting['key']];
                    }
                    $this->SendDebug('Setting', json_encode($setting), 0);
                    //Create variable accordingly
                    $profileName = str_replace('BSH', 'HomeConnect', $setting['key']);
                    $variableType = $this->getVariableType($value);
                    $settingDetails = json_decode($this->requestDataFromParent('homeappliances/' . $this->ReadPropertyString('HaID') . '/settings/' . $setting['key']), true);
                    $this->createVariableFromConstraints($profileName, $settingDetails['data'], 'Setting', $position);
                    $position++;
                    $this->SetValue($ident, $value);
                }

                $this->WriteAttributeString('Settings', json_encode($availableSettings));
            }
        }

        private function createVariableByData($data)
        {
            $ident = $this->getLastSnippet($data['key']);
            $displayName = isset($data['name']) ? $data['name'] : $this->Translate($ident);
            $profileName = str_replace('BSH', 'HomeConnect', $data['key']);
            $profile = IPS_VariableProfileExists($profileName) ? $profileName : '';
            $this->MaintainVariable($ident, $displayName, $this->getVariableType($data['value']), $profile, 0, true);
        }

        private function getVariableType($value)
        {
            switch (gettype($value)) {
                case 'double':
                    return VARIABLETYPE_FLOAT;

                case 'integer':
                    return VARIABLETYPE_INTEGER;

                case 'boolean':
                    return VARIABLETYPE_BOOLEAN;

                default:
                    return VARIABLETYPE_STRING;
            }
        }

        private function splitCamelCase($string)
        {
            preg_match_all('/(?:^|[A-Z])[a-z]+/', $string, $matches);
            return $this->Translate(implode(' ', $matches[0]));
        }

        private function createVariableFromConstraints($profileName, $data, $attribute, $position)
        {
            $this->SendDebug('UpdatingProfile', $profileName, 0);

            $this->SendDebug('OptionDetails', json_encode($data), 0);
            $ident = $this->getLastSnippet($data['key']);
            if ($attribute == 'Option') {
                $ident = $attribute . $ident;
            }

            $constraints = $data['constraints'];
            switch ($data['type']) {
                case 'Int':
                    $variableType = VARIABLETYPE_INTEGER;
                    break;

                case 'Double':
                    $variableType = VARIABLETYPE_FLOAT;
                    break;

                case 'Boolean':
                    $variableType = VARIABLETYPE_FLOAT;
                    break;

                default:
                    $variableType = VARIABLETYPE_STRING;
                    break;
            }

            switch ($variableType) {
                case VARIABLETYPE_INTEGER:
                case VARIABLETYPE_FLOAT:
                    if (!IPS_VariableProfileExists($profileName)) {
                        //Create profile
                        IPS_CreateVariableProfile($profileName, $variableType);
                    }
                    IPS_SetVariableProfileText($profileName, '', ' ' . $data['unit']);
                    IPS_SetVariableProfileValues($profileName, $constraints['min'], $constraints['max'], isset($constraints['stepsize']) ? $constraints['stepsize'] : 1);
                    $this->SendDebug('UpdatedProfile', $constraints['min'] . ' - ' . $constraints['max'], 0);
                    break;

                default:
                    $variableType = VARIABLETYPE_STRING;
                    if (!IPS_VariableProfileExists($profileName)) {
                        //Create profile
                        IPS_CreateVariableProfile($profileName, $variableType);
                    }
                    //Add potential new options
                    $newAssociations = [];
                    for ($i = 0; $i < count($constraints['allowedvalues']); $i++) {
                        $displayName = isset($constraints['displayvalues'][$i]) ? $constraints['displayvalues'][$i] : $this->getLastSnippet($constraints['allowedvalues'][$i]);
                        $newAssociations[$constraints['allowedvalues'][$i]] = $displayName;
                    }

                    //Get current options from profile
                    $oldAssociations = [];
                    foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
                        $oldAssociations[$association['Value']] = $association['Name'];
                    }
                    //Only refresh the profile if changes occured
                    $diffold = array_diff_assoc($oldAssociations, $newAssociations);
                    $diffnew = array_diff_assoc($newAssociations, $oldAssociations);
                    if ($diffold || $diffnew) {
                        //Clear profile if it exists
                        foreach (IPS_GetVariableProfile($profileName)['Associations'] as $association) {
                            IPS_SetVariableProfileAssociation($profileName, $association['Value'], '', '', -1);
                        }
                        foreach ($newAssociations as $value => $name) {
                            IPS_SetVariableProfileAssociation($profileName, $value, $name, '', -1);
                        }
                    }
                    break;

            }

            //Create variable with created profile
            if (!@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                $displayName = isset($data['name']) ? $data['name'] : $ident;
                $this->MaintainVariable($ident, $displayName, $variableType, $profileName, $position, true);
                $this->EnableAction($ident);
            }
        }

        private function switchable()
        {
            $restrictions = json_decode($this->ReadAttributeString('Restrictions'), true);
            $switchable = true;
            foreach ($restrictions as $restriction => $value) {
                if ($restriction != 'LocalControlActive') {
                    $switchable = $value;
                } else {
                    $switchable = !$value;
                }
                $switchable = $restriction == 'LocalControlActive' ? !$value : $value;
            }
            return $switchable;
        }

        private function updateRestrictions($ident, $data)
        {
            $restrictions = json_decode($this->ReadAttributeString('Restrictions'), true);
            $restrictions[$ident] = $data['value'];
            $this->WriteAttributeString('Restrictions', json_encode($restrictions));
        }

        private function getValidOptions()
        {
            $children = IPS_GetChildrenIDs($this->InstanceID);
            $options = [];
            foreach ($children as $child) {
                $object = IPS_GetObject($child);
                if (strpos($object['ObjectIdent'], 'Option') !== false) {
                    if ($object['ObjectIsHidden'] == false) {
                        // $options[str_replace('Option', '', $object['ObjectIdent'])] = $object['ObjectIdent'];
                        $options[$object['ObjectIdent']] = str_replace('Option', '', $object['ObjectIdent']);
                    }
                }
            }

            return $options;
        }

        private function setOptionsDisabled($disabled)
        {
            $options = $this->getValidOptions();
            foreach ($options as $ident => $key) {
                IPS_SetDisabled($this->GetIDForIdent($ident), $disabled);
            }
        }
    }