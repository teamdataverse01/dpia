<div class="card">
  <h1>Help and glossary</h1>
  <p>This module checks privacy risk in two situations: before CU works with an outside company (<strong>vendor risk assessment</strong>) and before CU starts a risky data activity (<strong>DPIA</strong>). Both follow the same steps:</p>
  <ol>
    <li>Describe the vendor or the activity.</li>
    <li>Answer the risk factor questions — the same questions for both.</li>
    <li>The system calculates the <strong>inherent risk</strong> (before safeguards).</li>
    <li>It recommends safeguards (<strong>controls</strong>).</li>
    <li>Control owners record which safeguards are in place, with evidence.</li>
    <li>The system calculates the <strong>residual risk</strong> (after safeguards).</li>
    <li>Gaps become remediation actions with owners and dates.</li>
    <li>The right people approve it, reject it, or accept the risk for a limited time.</li>
    <li>It is monitored and reassessed when its review date comes.</li>
  </ol>
  <h2>Glossary</h2>
  <dl class="kv">
    <?php foreach (['DPIA' => 'dpia', 'DSAR' => 'dsar', 'Inherent risk' => 'inherent', 'Residual risk' => 'residual', 'Control' => 'control', 'Third party / vendor' => 'third_party', 'Cross-border transfer' => 'cross_border', 'Legal basis' => 'legal_basis', 'Data sharing' => 'data_sharing', 'RBAC' => 'rbac'] as $t => $k): ?>
      <dt><?= e($t) ?></dt><dd><?= e(glossary($k)) ?></dd>
    <?php endforeach; ?>
  </dl>
  <h2>How ratings are decided</h2>
  <ul>
    <li>Inherent risk is a weighted average of the factor scores (1–5): up to 2.0 is Low, up to 3.5 is Medium, above that is High.</li>
    <li>High becomes Low only when at least two control types are cleared and one of them is Technology. One cleared type brings High to Medium.</li>
    <li>Medium becomes Low when any one control type is cleared.</li>
    <li>Very Low is given only when a Reviewer or Risk Approver confirms that a Low remaining risk is negligible.</li>
    <li>A vendor's risk score counts in a DPIA only when the activity shares data with that vendor.</li>
  </ul>
</div>
