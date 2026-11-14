<figure class="diagram">
    <svg class="dg" viewBox="0 0 600 450" role="img" aria-labelledby="life-title life-desc">
        <title id="life-title">Cycle de vie d’un serveur dans StackCard</title>
        <desc id="life-desc">1 : un admin crée le serveur, qui est « En attente d’enrôlement ». 2 : il génère un jeton d’enrôlement, valable 24 heures et à usage unique. 3 : l’agent s’enrôle avec ce jeton, le serveur devient « Actif » et son slug est figé au premier inventaire. Sans envoi depuis 3 heures, il apparaît « Silencieux », puis redevient « Actif » au prochain envoi. Une réinstallation passe par un nouveau jeton sur la même fiche. 4 : « Révoquer » coupe les envois ; un nouveau jeton remet le serveur « En attente », avec le même slug.</desc>
        <defs>
            <marker id="life-h" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" class="head"/></marker>
        </defs>

        {{-- 1. Created --}}
        <rect class="box k-central" x="20" y="40" width="230" height="96" rx="14"/>
        <text class="t-title" x="38" y="70">1 · Créé</text>
        <text class="t-sub" x="38" y="93">Serveurs → Nouveau</text>
        <rect class="k-gray" x="38" y="104" width="182" height="22" rx="11" stroke="none"/>
        <text class="t-label" x="50" y="120" style="fill: var(--gray)">En attente d’enrôlement</text>

        {{-- 2. Token --}}
        <rect class="box k-plain" x="320" y="40" width="230" height="96" rx="14" stroke-dasharray="6 5"/>
        <text class="t-title" x="338" y="70">2 · Jeton</text>
        <text class="t-sub" x="338" y="93">valable 24 h,</text>
        <text class="t-sub" x="338" y="114">à usage unique</text>

        {{-- 3. Active --}}
        <rect class="box k-agent" x="320" y="190" width="230" height="96" rx="14"/>
        <text class="t-title" x="338" y="220">3 · Enrôlé</text>
        <text class="t-sub" x="338" y="243">slug figé au 1er envoi</text>
        <rect class="k-ok" x="338" y="254" width="60" height="22" rx="11" stroke="none"/>
        <text class="t-label" x="350" y="270" style="fill: var(--ok)">Actif</text>

        {{-- Silent --}}
        <rect class="box k-plain" x="20" y="190" width="230" height="96" rx="14"/>
        <text class="t-title" x="38" y="220">Sans nouvelles</text>
        <text class="t-sub" x="38" y="243">aucun envoi depuis 3 h</text>
        <rect class="k-warn" x="38" y="254" width="94" height="22" rx="11" stroke="none"/>
        <text class="t-label" x="50" y="270" style="fill: var(--warn)">Silencieux</text>

        {{-- 4. Revoked --}}
        <rect class="box k-plain" x="320" y="340" width="230" height="96" rx="14"/>
        <text class="t-title" x="338" y="370">4 · Révoqué</text>
        <text class="t-sub" x="338" y="393">plus aucun envoi accepté</text>
        <rect class="k-danger" x="338" y="404" width="82" height="22" rx="11" stroke="none"/>
        <text class="t-label" x="350" y="420" style="fill: var(--danger)">Révoqué</text>

        {{-- Note --}}
        <rect class="group" x="20" y="340" width="230" height="96" rx="14"/>
        <text class="t-sub" x="38" y="368">Nouveau jeton sur la fiche :</text>
        <text class="t-sub" x="38" y="390">retour « En attente »,</text>
        <text class="t-sub" x="38" y="412">slug et historique gardés</text>

        {{-- Transitions --}}
        <path class="arrow" d="M250 88 L316 88" marker-end="url(#life-h)"/>
        <text class="t-label t-center" x="285" y="78">générer</text>
        <path class="arrow agent" d="M370 136 L370 186" marker-end="url(#life-h)"/>
        <text class="t-label" x="362" y="167" text-anchor="end">enrôlement</text>
        <path class="arrow dashed" d="M500 190 L500 140" marker-end="url(#life-h)"/>
        <text class="t-label" x="492" y="167" text-anchor="end">réinstallation</text>
        <path class="arrow" d="M320 222 L254 222" marker-end="url(#life-h)"/>
        <path class="arrow" d="M250 254 L316 254" marker-end="url(#life-h)"/>
        <path class="arrow" d="M435 286 L435 336" marker-end="url(#life-h)"/>
        <text class="t-label" x="443" y="316">Révoquer</text>
        <path class="arrow dashed" d="M320 388 L254 388" marker-end="url(#life-h)"/>
        <path class="arrow dashed" d="M550 388 L580 388 L580 88 L554 88" marker-end="url(#life-h)"/>
        <text class="t-label t-center" transform="translate(594 238) rotate(-90)">nouveau jeton</text>
    </svg>
    <figcaption>Le slug ne change pas au fil de ce cycle : un serveur réinstallé ou réenrôlé garde sa fiche. Seule la suppression du serveur le libère.</figcaption>
</figure>
