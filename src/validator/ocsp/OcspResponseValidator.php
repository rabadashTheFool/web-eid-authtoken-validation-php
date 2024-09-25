<?php

/*
 * Copyright (c) 2022-2024 Estonian Information System Authority
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace web_eid\web_eid_authtoken_validation_php\validator\ocsp;

use BadFunctionCallException;
use DateInterval;
use DateTime;
use web_eid\web_eid_authtoken_validation_php\exceptions\OCSPCertificateException;
use phpseclib3\File\X509;
use web_eid\web_eid_authtoken_validation_php\ocsp\OcspBasicResponse;
use web_eid\web_eid_authtoken_validation_php\ocsp\OcspResponse;
use web_eid\web_eid_authtoken_validation_php\exceptions\UserCertificateOCSPCheckFailedException;
use web_eid\web_eid_authtoken_validation_php\exceptions\UserCertificateRevokedException;
use web_eid\web_eid_authtoken_validation_php\util\DateAndTime;

final class OcspResponseValidator
{

    /**
     * Indicates that a X.509 Certificates corresponding private key may be used by an authority to sign OCSP responses.
     * <p>
     * https://oidref.com/1.3.6.1.5.5.7.3.9.
     */
    private const OCSP_SIGNING = "id-kp-OCSPSigning";

    private const ALLOWED_TIME_SKEW = 15;

    public function __construct()
    {
        throw new BadFunctionCallException("Utility class");
    }

    public static function validateHasSigningExtension(X509 $certificate): void
    {
        if (!$certificate->getExtension("id-ce-extKeyUsage") || !in_array(self::OCSP_SIGNING, $certificate->getExtension("id-ce-extKeyUsage"))) {
            throw new OCSPCertificateException("Certificate " . $certificate->getSubjectDN(X509::DN_STRING) . " does not contain the key usage extension for OCSP response signing");
        }
    }

    public static function validateResponseSignature(OcspBasicResponse $basicResponse, X509 $responderCert): void
    {
        // get public key from responder certificate in order to verify signature on response
        $publicKey = $responderCert->getPublicKey()->withHash($basicResponse->getSignatureAlgorithm());
        // verify response data
        $encodedTbsResponseData = $basicResponse->getEncodedResponseData();
        $signature = $basicResponse->getSignature();

        if (!$publicKey->verify($encodedTbsResponseData, $signature)) {
            throw new UserCertificateOCSPCheckFailedException("OCSP response signature is invalid");
        }
    }

    public static function validateCertificateStatusUpdateTime(OcspBasicResponse $basicResponse, DateTime $producedAt): void
    {
        // From RFC 2560, https://www.ietf.org/rfc/rfc2560.txt:
        // 4.2.2.  Notes on OCSP Responses
        // 4.2.2.1.  Time
        //   Responses whose nextUpdate value is earlier than
        //   the local system time value SHOULD be considered unreliable.
        //   Responses whose thisUpdate time is later than the local system time
        //   SHOULD be considered unreliable.
        //   If nextUpdate is not set, the responder is indicating that newer
        //   revocation information is available all the time.

        $notAllowedBefore = (clone $producedAt)->sub(new DateInterval('PT' . self::ALLOWED_TIME_SKEW . 'S'));
        $notAllowedAfter = (clone $producedAt)->add(new DateInterval('PT' . self::ALLOWED_TIME_SKEW . 'S'));

        $thisUpdate = $basicResponse->getThisUpdate();
        $nextUpdate = $basicResponse->getNextUpdate();

        if ($notAllowedAfter < $thisUpdate || $notAllowedBefore > (!is_null($nextUpdate) ? $nextUpdate : $thisUpdate)) {

            throw new UserCertificateOCSPCheckFailedException("Certificate status update time check failed: " .
                "notAllowedBefore: " . DateAndTime::toUtcString($notAllowedBefore) .
                ", notAllowedAfter: " . DateAndTime::toUtcString($notAllowedAfter) .
                ", thisUpdate: " . DateAndTime::toUtcString($thisUpdate) .
                ", nextUpdate: " . DateAndTime::toUtcString($nextUpdate));
        }
    }

    public static function validateSubjectCertificateStatus(OcspResponse $response): void
    {
        if (is_null($response->isRevoked())) {
            throw new UserCertificateRevokedException("Unknown status");
        }
        if ($response->isRevoked() === false) {
            return;
        }
        if ($response->isRevoked() === true) {
            throw ($response->getRevokeReason() == "") ? new UserCertificateRevokedException() : new UserCertificateRevokedException("Revocation reason: " . $response->getRevokeReason());
        }
        throw new UserCertificateRevokedException("Status is neither good, revoked nor unknown");
    }
}
