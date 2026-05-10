// M_PLAYWRIGHT_OCRE_PARCOURS — helpers partagés tests Ocre
const { execSync } = require('child_process');

// Récupère le dernier magic link en DB pour un email
async function getMagicLinkFromDb(email) {
  const cmd = `mariadb ocre_meta -N -e "SELECT t.token FROM auth_magic_tokens t JOIN auth_users u ON u.id=t.user_id WHERE u.email='${email.replace(/'/g, "''")}' ORDER BY t.id DESC LIMIT 1" 2>/dev/null`;
  const token = execSync(cmd).toString().trim();
  if (!token) throw new Error(`No magic token for ${email}`);
  return `https://auth.ocre.immo/api/magic-link/validate.php?token=${token}&app=agent`;
}

// Cleanup utilisateur test
async function cleanupTestUser(email) {
  const safe = email.replace(/'/g, "''");
  try {
    execSync(`mariadb ocre_meta -e "DELETE m FROM auth_magic_tokens m JOIN auth_users u ON u.id=m.user_id WHERE u.email='${safe}'" 2>/dev/null`);
    execSync(`mariadb ocre_meta -e "DELETE FROM auth_user_modules WHERE user_id IN (SELECT id FROM auth_users WHERE email='${safe}')" 2>/dev/null`);
    execSync(`mariadb ocre_meta -e "DELETE FROM auth_users WHERE email='${safe}'" 2>/dev/null`);
  } catch (e) { /* swallow if mariadb absent */ }
}

// Email test unique par run
function genTestEmail(suffix = '') {
  return `e2e-test+${Date.now()}${suffix}@example.com`;
}

// Console errors collector
function collectConsoleErrors(page) {
  const errors = [];
  page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });
  page.on('pageerror', err => errors.push(err.message));
  return errors;
}

// Pré-condition : INSERT user existant pour tests login direct
function ensureExistingUser(email, firstName = 'TestExist') {
  const safe = email.replace(/'/g, "''");
  try {
    execSync(`mariadb ocre_meta -e "INSERT IGNORE INTO auth_users (email, first_name) VALUES ('${safe}', '${firstName}')" 2>/dev/null`);
  } catch (e) {}
}

module.exports = { getMagicLinkFromDb, cleanupTestUser, genTestEmail, collectConsoleErrors, ensureExistingUser };
