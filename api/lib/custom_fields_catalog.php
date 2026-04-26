<?php
// V20 phase 5 — catalog officiel 13 champs personnalisés activables par WSp.
const CUSTOM_FIELDS_CATALOG = [
  'commission_code'  => ['label' => 'Code commission',     'type' => 'text',   'maxlen' => 32],
  'commission_split' => ['label' => 'Répartition (%)',     'type' => 'number', 'min' => 0, 'max' => 100],
  'budget_max'       => ['label' => 'Budget max client',   'type' => 'number', 'unit' => 'devise'],
  'acquereur_type'   => ['label' => 'Type acquéreur',      'type' => 'select', 'options' => ['Investisseur','Résidence principale','Résidence secondaire','Locatif']],
  'lead_source'      => ['label' => 'Source du lead',      'type' => 'select', 'options' => ['Site vitrine','Bouche à oreille','Réseau','Plateforme tierce','Salon','Autre']],
  'urgency'          => ['label' => 'Délai souhaité',      'type' => 'select', 'options' => ['Moins de 3 mois','3 à 6 mois','6 à 12 mois','Plus de 12 mois','Indéfini']],
  'financing_mode'   => ['label' => 'Mode de financement', 'type' => 'select', 'options' => ['Cash','Crédit','Mixte','À déterminer']],
  'nationality'      => ['label' => 'Nationalité client',  'type' => 'text',   'maxlen' => 64],
  'last_contact_at'  => ['label' => 'Dernier contact',     'type' => 'date'],
  'next_action_at'   => ['label' => 'Prochaine action',    'type' => 'date'],
  'internal_ref'     => ['label' => 'Référence interne',   'type' => 'text',   'maxlen' => 32],
  'tag_1'            => ['label' => 'Tag libre 1',         'type' => 'text',   'maxlen' => 24],
  'tag_2'            => ['label' => 'Tag libre 2',         'type' => 'text',   'maxlen' => 24],
];
