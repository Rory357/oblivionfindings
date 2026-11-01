<?php

namespace App\Domain\SecurityDevices\Iot\Mqtt;

use RuntimeException;

/** The MQTT broker closed the connection or stopped answering; the listener reconnects. */
final class MqttConnectionLost extends RuntimeException {}
