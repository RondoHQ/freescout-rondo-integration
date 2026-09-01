<ul class="nav nav-tabs margin-bottom">
    <li class="{{ Helper::isRoute(['rondointegration.settings','rondointegration.settings.save','rondointegration.settings.verify']) ? 'active' : '' }}"><a href="{{ route('rondointegration.settings') }}">{{ __('Connection & appearance') }}</a></li>
    <li class="{{ Helper::isRoute(['rondointegration.mailboxes','rondointegration.mailboxes.verify','rondointegration.mailboxes.state']) ? 'active' : '' }}"><a href="{{ route('rondointegration.mailboxes') }}">{{ __('Mailbox mappings') }}</a></li>
    <li class="{{ Helper::isRoute(['rondointegration.bindings','rondointegration.bindings.disable','rondointegration.bindings.replace']) ? 'active' : '' }}"><a href="{{ route('rondointegration.bindings') }}">{{ __('Rondo identities') }}</a></li>
</ul>
