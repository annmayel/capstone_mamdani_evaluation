<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php/otp_auth.php';
require_once __DIR__ . '/../php/student_auth.php';

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

	public function testEnvironmentValueCanBeReadFromServerVariables(): void
	{
		$name = 'FUZZY_OTP_TEST_SERVER_ENV_' . bin2hex(random_bytes(8));
		$_SERVER[$name] = 'server-value';

		try {
			self::assertSame('server-value', fuzzy_otp_environment_value($name));
		} finally {
			unset($_SERVER[$name]);
		}
	}

	public function testStudentNeedsVerifiedEmailAndChairpersonApproval(): void
	{
		self::assertSame('email_unverified', student_account_access_status([
			'email_verified_at' => null,
			'student_approved_at' => null,
		]));
		self::assertSame('approval_pending', student_account_access_status([
			'email_verified_at' => '2026-11-19 01:00:00',
			'student_approved_at' => null,
		]));
		self::assertSame('approved', student_account_access_status([
			'email_verified_at' => '2026-11-19 01:00:00',
			'student_approved_at' => '2026-11-19 02:00:00',
		]));
	}
}
