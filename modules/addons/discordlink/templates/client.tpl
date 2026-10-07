<h2>Discord Account</h2>
{if $discordlinkMessage}<div class="alert alert-info">{$discordlinkMessage|escape}</div>{/if}
{if $discordlinkAccount}
<p>Connected as <strong>{$discordlinkAccount.username|escape}</strong> (ID: {$discordlinkAccount.id|escape})</p>
<form method="post" action="index.php?m=discordlink&amp;action=unlink">
<input type="hidden" name="discordlink_csrf" value="{$discordlinkCsrf|escape}">
<button type="submit" class="btn btn-danger">Unlink Discord</button>
</form>
{else}
<p>Connect your Discord account securely using Discord OAuth2.</p>
<form method="post" action="index.php?m=discordlink&amp;action=connect">
<input type="hidden" name="discordlink_csrf" value="{$discordlinkCsrf|escape}">
<button type="submit" class="btn btn-primary">Link Discord Account</button>
</form>
{/if}
