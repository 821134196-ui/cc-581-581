export const STATUS_BADGE = {
  submitted: 'blue',
  supplementing: 'amber',
  community_rejected: 'red',
  pending_street: 'blue',
  street_rejected: 'red',
  approved: 'blue',
  paid: 'green',
  merged: 'gray',
}

export const HEATING_LABELS = {
  coal: '燃煤自采暖',
  electric: '电采暖',
  gas: '燃气自采暖',
  central_heating_self_paid: '集中供暖（自费）',
}

export const DOC_STATUS = {
  required: ['gray', '未提交'],
  submitted: ['green', '已提交'],
  expired: ['red', '已过期'],
}

export function yuan(fen) {
  return fen == null ? '-' : `¥${(fen / 100).toFixed(2)}`
}
