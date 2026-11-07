<?php

namespace App\Domain\SecurityDevices\Iot\Relay\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Oblivion's sensors certificate authority (S&D Phase E, E5). The private key is stored encrypted
 * with the app key and never leaves the server; only the certificate is shown or downloaded.
 *
 * @property string $purpose
 * @property string $certificate_pem
 * @property string $private_key_encrypted
 * @property int $next_serial
 */
final class SensorCertificateAuthority extends Model
{
    protected $table = 'security_device_certificate_authorities';

    protected $fillable = ['purpose', 'certificate_pem', 'private_key_encrypted', 'next_serial', 'expires_at'];

    protected $hidden = ['private_key_encrypted'];

    protected $casts = [
        'private_key_encrypted' => 'encrypted',
        'next_serial' => 'integer',
        'expires_at' => 'immutable_datetime',
    ];
}
