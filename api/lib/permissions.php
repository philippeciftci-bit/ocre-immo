<?php
// M114b — Helper permissions par role pour tous les endpoints sensibles.
// Utilise team_get_role() pour resoudre le role courant de l'user dans le tenant.
require_once __DIR__ . '/../team/_lib.php';

const PERM_ROLES_ALL = ['owner', 'manager', 'collaborator', 'viewer'];
const PERM_ROLES_OWNER_MANAGER = ['owner', 'manager'];
const PERM_ROLES_OWNER_ONLY = ['owner'];

// Bloque l'access si l'user n'a pas un role dans la liste $allowed.
// Termine la requete avec HTTP 403 + JSON error si refus.
function requireRole(array $allowed, array $user): void {
    $tenant = $user['slug'] ?? null;
    $userId = (int) ($user['user_id'] ?? 0);
    $email = $user['email'] ?? null;
    if (!$tenant || !$userId) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Non authentifie']);
        exit;
    }
    $role = team_get_role($tenant, $userId, $email);
    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'forbidden',
            'message' => 'Permission insuffisante. Role requis : ' . implode(', ', $allowed) . '. Votre role : ' . $role,
            'required_roles' => $allowed,
            'your_role' => $role,
        ]);
        exit;
    }
}

// Verifie si l'user peut editer un dossier specifique.
// Owner+Manager : edit tous les dossiers. Collaborator : seulement created_by_user_id == sien.
function canEditDossier(array $user, array $dossier): bool {
    $role = team_get_role($user['slug'], (int) $user['user_id'], $user['email'] ?? null);
    if (in_array($role, ['owner', 'manager'], true)) return true;
    if ($role === 'collaborator' && (int) ($dossier['user_id'] ?? $dossier['created_by'] ?? 0) === (int) $user['user_id']) return true;
    return false;
}

// Helper pour endpoint qui retourne juste mon role.
function getMyRole(array $user): string {
    return team_get_role($user['slug'], (int) $user['user_id'], $user['email'] ?? null);
}
