<?php

declare(strict_types=1);

namespace Skir\Runtime\Tests;

use PHPUnit\Framework\TestCase;
use Skir\Runtime\Field;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Runtime\Variant;

final class MethodDescriptorTest extends TestCase
{
    public function test_it_describes_a_skir_rpc_method(): void
    {
        $requestType = Type::struct([]);
        $responseType = Type::string();

        $method = new MethodDescriptor(
            name: 'GetUser',
            number: 3180856469,
            requestType: $requestType,
            responseType: $responseType,
        );

        $this->assertSame('GetUser', $method->name);
        $this->assertSame(3180856469, $method->number);
        $this->assertSame($requestType, $method->requestType);
        $this->assertSame($responseType, $method->responseType);
    }

    public function test_types_can_carry_the_schema_names_and_doc_comments(): void
    {
        $status = Type::enum(
            [
                Variant::constant('pending', 1, 'Not accepted yet.'),
                Variant::wrapper('refused', 2, Type::string()),
            ],
            recordId: 'invitations/invitations.skir:Invitation.Status',
            doc: 'Where an invitation stands.',
        );
        $invitation = Type::struct(
            [Field::value('status', 0, $status, 'Changes when the invitee answers.')],
            recordId: 'invitations/invitations.skir:Invitation',
            doc: 'One invitation.',
        );
        $method = new MethodDescriptor('GetInvitation', 1, Type::string(), $invitation, doc: 'Looks up an invitation.');

        $this->assertSame('invitations/invitations.skir:Invitation', $invitation->recordId);
        $this->assertSame('One invitation.', $invitation->doc);
        $this->assertSame('Changes when the invitee answers.', $invitation->fields[0]->doc);
        $this->assertSame('invitations/invitations.skir:Invitation.Status', $status->recordId);
        $this->assertSame('Not accepted yet.', $status->variants[0]->doc);
        $this->assertNull($status->variants[1]->doc);
        $this->assertSame('Looks up an invitation.', $method->doc);
    }

    public function test_names_and_doc_comments_are_optional(): void
    {
        $type = Type::struct([Field::value('name', 0, Type::string())]);

        $this->assertNull($type->recordId);
        $this->assertNull($type->doc);
        $this->assertNull($type->fields[0]->doc);
        $this->assertNull((new MethodDescriptor('Ping', 2, $type, $type))->doc);
    }
}
