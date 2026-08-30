// Fırsat aşaması rozeti — `pipeline_stage.color` (sabit token adı) `tokenBadgeVariant` ile
// `Badge` varyantına çevrilir. Hex/rastgele renk KULLANILMAZ (bkz. token sözleşmesi).
import { useTranslation } from 'react-i18next'
import { Badge } from '../../../components/ui'
import { tokenBadgeVariant } from '../../../components/shared/tokenBadgeVariant'
import { stageLabel } from '../utils/stageLabel'
import type { PipelineStage } from '../types'

export type DealStageBadgeProps = {
  stage: PipelineStage | null
}

export function DealStageBadge({ stage }: DealStageBadgeProps) {
  const { t } = useTranslation('deals')
  if (!stage) return <span className="text-sm text-fg-muted">—</span>
  return <Badge variant={tokenBadgeVariant(stage.color)}>{stageLabel(t, stage)}</Badge>
}
