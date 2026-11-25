import assert from 'node:assert/strict';
import test from 'node:test';
import { passwordPolicyErrors } from '../../resources/js/password-policy.js';

test('matches the new-password length, byte limit and confirmation rules', () => {
    const validate = (password, confirmation = password) => passwordPolicyErrors(password, confirmation, 10, 72);
    assert.ok(validate('').passwordError);
    assert.ok(validate('Password9').passwordError);
    assert.equal(validate('Password10').passwordError, '');
    assert.equal(validate('Password110').passwordError, '');
    assert.equal(validate('é'.repeat(36)).passwordError, '');
    assert.ok(validate('é'.repeat(36) + 'a').passwordError);
    assert.ok(validate('Password10', 'Password11').confirmationError);
    assert.ok(validate('1234567890').passwordError);
});
