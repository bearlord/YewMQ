<?php

namespace App\Modules\Mqtt\Services;

use App\Models\Extension\MqttRetainedMessage;

class MqttRetainedMessageService
{
    /**
     * @param array $data
     * @return bool
     */
    public function saveRetainedMessage(array $data): bool
    {
        $model = new MqttRetainedMessage();
        $model->setAttributes($data, false);
        $model->save(false);

        return true;
    }
}