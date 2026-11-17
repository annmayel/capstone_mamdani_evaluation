<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php/otp_auth.php';

final class EmailOtpAuthTest extends TestCase
{
	public function testGeneratedCodesAlwaysContainSixDigits(): void
	{
		for ($index = 0; $index < 20; $index++) {
			self::assertMatchesRegularExpression('/^\d{6}$/D', fuzzy_otp_generate_code());
		}
	}

	public function testCodeHashIsBoundToItsSessionToken(): void
	{
		$code = '012345';
		$firstToken = bin2hex(random_bytes(32));
		$secondToken = bin2hex(random_bytes(32));

		$hash = fuzzy_otp_hash_code($code, $firstToken);

		self::assertSame(hash_hmac('sha256', $code, $firstToken), $hash);
		self::assertNotSame($code, $hash);
		self::assertNotSame($hash, fuzzy_otp_hash_code($code, $secondToken));
	}

	public function testMaskedEmailDoesNotExposeTheFullLocalPart(): void
	{
		self::assertSame('s******@csucc.edu.ph', fuzzy_otp_mask_email('student@csucc.edu.ph'));
	}
}
