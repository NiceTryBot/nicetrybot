<?php

namespace App\Support;

/**
 * Anthropic turned a request away because the key belongs to a whole organization rather than one
 * workspace, so every request with it must name the workspace.
 */
class AnthropicWorkspaceRequired extends AnthropicException
{
    //
}
