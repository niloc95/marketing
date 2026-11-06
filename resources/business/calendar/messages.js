/**
 * Every sentence the calendar script shows. Kept in one file so the copy
 * rules test (BookingFoundationTest) can read it: no dashes, no hyphenated
 * words, "team member" not "provider", "Find" not "Search".
 */
export const MSG = {
  loadFailed: 'Could not load the calendar. Check your connection and try again.',
  expired: 'This page was open for a long time. Reload it and try again.',
  failed: 'That did not work. Try again.',
  moved: 'Moved.',
  saved: 'Saved.',
  booked: 'Booked.',
  statusSaved: 'Status saved.',
  confirmMove: (name, when) => `Move ${name} to ${when}?`,
  noAppointments: 'No appointments.',
  noneMatch: 'Nobody matches that.',
  more: (n) => `${n} more`,
  until: 'to',
  finding: 'Finding free times…',
  noFree: 'No free time in the next 60 days. Check their working hours.',
  noFreeDay: 'Nothing free that day.',
  freeOn: (day) => `Free on ${day}`,
  chosenTime: (label, place) => `Moving to ${label}${place ? `, at ${place}` : ''}.`,
  at: 'at',
  noCustomer: 'No customer',
  nowLine: 'Now',
  newCustomer: 'New customer',
  changeCustomer: 'Change',
  everyone: 'Everyone',
  online: 'Online',
  noAddress: 'Address not given',
  noMessages: 'Nothing sent yet.',
  toTeam: ' to the team',
  queued: 'It will go out within a minute.',
  paymentLabel: (label, amount, dueBy) => `${label}: ${amount}${dueBy ? `, held until ${dueBy}` : ''}`,
  paymentRow: (p) => `${p.at}: ${p.gateway} ${p.reference}, ${p.amount}, ${p.status}${p.test ? ', test' : ''}`,
  markPaidAsk: 'Mark the deposit as paid? Do this when the customer paid you another way.',
  markRefundedAsk: 'Mark the deposit as refunded? Refund it in your PayFast or Paystack account first.',
};
