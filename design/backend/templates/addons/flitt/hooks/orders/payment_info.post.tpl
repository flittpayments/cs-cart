{if $sendLink != ''}
    <a href="{$sendLink}">{__("flitt.send_payment_link")}</a>
    {if $error['message'] != ''}
        <p>{$error['message']}, {$error['request_id']}</p>
    {/if}
{/if}
