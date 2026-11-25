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

	public function testMailConfigurationReadsStandardVariablesAndPreservesPassword(): void
	{
		$this->withMailEnvironment([
			'MAIL_MAILER' => 'smtp',
			'MAIL_HOST' => 'smtp.example.com',
			'MAIL_PORT' => '465',
			'MAIL_USERNAME' => 'mailer@example.com',
			'MAIL_PASSWORD' => ' pass word ',
			'MAIL_ENCRYPTION' => 'ssl',
			'MAIL_FROM_ADDRESS' => 'mailer@example.com',
			'MAIL_FROM_NAME' => 'Capstone Test',
		], function (): void {
			$diagnostics = fuzzy_otp_mail_diagnostics();

			self::assertTrue($diagnostics['configured'], implode(' ', $diagnostics['issues']));
			self::assertSame('smtp', $diagnostics['settings']['mailer']);
			self::assertSame(465, $diagnostics['settings']['port']);
			self::assertSame('ssl', $diagnostics['settings']['encryption']);
			self::assertSame(' pass word ', fuzzy_otp_mail_config()['password']);
		});
	}

	public function testMailConfigurationRejectsInvalidValuesWithoutEchoingSecrets(): void
	{
		$this->withMailEnvironment([
			'MAIL_MAILER' => 'sendmail',
			'MAIL_HOST' => 'localhost',
			'MAIL_PORT' => '70000',
			'MAIL_USERNAME' => 'mailer@example.com',
			'MAIL_PASSWORD' => 'test-only-secret',
			'MAIL_ENCRYPTION' => 'none',
			'MAIL_FROM_ADDRESS' => 'not-an-address',
			'MAIL_FROM_NAME' => 'Capstone Test',
		], function (): void {
			$diagnostics = fuzzy_otp_mail_diagnostics();
			$diagnosticText = implode(' ', $diagnostics['issues']);

			self::assertFalse($diagnostics['configured']);
			self::assertStringContainsString('MAIL_MAILER', $diagnosticText);
			self::assertStringContainsString('MAIL_PORT', $diagnosticText);
			self::assertStringContainsString('MAIL_ENCRYPTION', $diagnosticText);
			self::assertStringContainsString('MAIL_FROM_ADDRESS', $diagnosticText);
			self::assertStringNotContainsString('test-only-secret', $diagnosticText);
			self::assertNull(fuzzy_otp_mail_config());
		});
	}

	public function testStudentNeedsChairpersonApprovalButNotEmailVerification(): void
	{
		self::assertSame('approval_pending', student_account_access_status([
			'email_verified_at' => null,
			'student_approved_at' => null,
		]));
		self::assertSame('approval_pending', student_account_access_status([
			'email_verified_at' => '2026-11-19 01:00:00',
			'student_approved_at' => null,
		]));
		self::assertSame('approved', student_account_access_status([
			'email_verified_at' => null,
			'student_approved_at' => '2026-11-19 02:00:00',
		]));
	}

	private function withMailEnvironment(array $values, callable $callback): void
	{
		$names = [
			'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
			'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
			'FUZZY_SMTP_HOST', 'FUZZY_SMTP_PORT', 'FUZZY_SMTP_ENCRYPTION',
			'FUZZY_SMTP_USERNAME', 'FUZZY_SMTP_PASSWORD', 'FUZZY_SMTP_FROM_EMAIL',
			'FUZZY_SMTP_FROM_NAME',
		];
		$originalEnvironment = [];
		$originalServer = [];
		$originalEnv = [];
		foreach ($names as $name) {
			$originalEnvironment[$name] = getenv($name);
			$originalServer[$name] = $_SERVER[$name] ?? null;
			$originalEnv[$name] = $_ENV[$name] ?? null;
			putenv($name);
			unset($_SERVER[$name], $_ENV[$name]);
		}

		try {
			foreach ($values as $name => $value) {
				putenv($name . '=' . $value);
			}
			$callback();
		} finally {
			foreach ($names as $name) {
				if ($originalEnvironment[$name] === false) {
					putenv($name);
				} else {
					putenv($name . '=' . $originalEnvironment[$name]);
				}
				if ($originalServer[$name] !== null) {
					$_SERVER[$name] = $originalServer[$name];
				}
				if ($originalEnv[$name] !== null) {
					$_ENV[$name] = $originalEnv[$name];
				}
			}
		}
	}
}
