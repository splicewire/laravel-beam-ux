<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Database\Connection;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Throwable;

/** Advisory only: Record Page lock-set rechecks need a fresh committed snapshot per statement. */
final class RecordPageIsolationAudit implements DoctorAudit
{
    private const CHECK = 'Record Page declaration isolation';

    public function run(): array
    {
        return $this->inspect((new BeamUxEntry)->getConnection());
    }

    /** The connection is the addressed Entry's, not a separate central/default connection. */
    public function inspect(Connection $connection): array
    {
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [Finding::inconclusive(self::CHECK, 'This MySQL/MariaDB isolation advisory does not evaluate this driver. PostgreSQL hosts use the documented READ COMMITTED prerequisite.')];
        }
        try {
            $row = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level');
            $level = str_replace('-', ' ', strtoupper((string) ($row->isolation_level ?? '')));
        } catch (Throwable) {
            return [Finding::inconclusive(self::CHECK, 'The entry connection transaction isolation could not be read. Verify READ COMMITTED before relying on concurrent Record Page declaration uniqueness.')];
        }
        if ($level === 'READ COMMITTED') {
            return [Finding::pass(self::CHECK, 'The entry connection reports READ COMMITTED, the supported MySQL/MariaDB prerequisite for Record Page candidate-set rechecks.')];
        }
        if (! in_array($level, ['REPEATABLE READ', 'SERIALIZABLE', 'READ UNCOMMITTED'], true)) {
            return [Finding::inconclusive(self::CHECK, 'The entry connection did not report a recognized isolation level. Verify READ COMMITTED.')];
        }

        return [Finding::warn(self::CHECK, 'The entry connection is not READ COMMITTED. In particular, REPEATABLE READ may hide a concurrent Record Page declaration from the post-lock snapshot recheck. Configure READ COMMITTED before relying on declaration uniqueness; this advisory does not block saves or change isolation.')];
    }
}
